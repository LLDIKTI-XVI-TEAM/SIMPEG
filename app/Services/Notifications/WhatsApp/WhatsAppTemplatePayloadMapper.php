<?php

namespace App\Services\Notifications\WhatsApp;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class WhatsAppTemplatePayloadMapper
{
    private const INDONESIAN_MONTHS = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    public function __construct(
        private readonly WhatsAppPrivacyGuard $privacy,
        private readonly WhatsAppRuntimeConfig $runtime,
    ) {}

    /**
     * Memetakan event domain ke template resmi WhatsApp Business dan variabel aman.
     * Mengembalikan null (fail-closed) jika payload tidak valid atau gagal privacy guard.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function map(string $eventKey, Employee $recipient, ?array $data = null): ?WhatsAppMappedTemplate
    {
        $data ??= [];

        $mapped = match ($eventKey) {
            'cuti.pengajuan_baru',
            'cuti.menunggu_persetujuan' => $this->mapCutiPerluTindakan($eventKey, $recipient, $data),

            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.ditangguhkan_tugas_dinas',
            'cuti.dikembalikan_karena_rollover',
            'cuti.perlu_perubahan',
            'cuti.tidak_disetujui' => $this->mapCutiStatus($eventKey, $recipient, $data),

            'ews.kenaikan_pangkat',
            'ews.kgb',
            'ews.pensiun',
            'ews.kontrak_pppk',
            'ews.satyalancana' => $this->mapEwsPengingat($eventKey, $recipient, $data),

            default => null,
        };

        if ($mapped === null) {
            return null;
        }

        if (! $this->privacy->areVariablesSafe($mapped->variables) || ! $this->privacy->areVariablesSafe($mapped->bodyVariables)) {
            return null;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapCutiPerluTindakan(string $eventKey, Employee $recipient, array $data): ?WhatsAppMappedTemplate
    {
        $eventTemplates = $this->runtime->eventTemplates();
        $templateKey = $eventTemplates[$eventKey] ?? null;
        if (! is_string($templateKey) || trim($templateKey) === '') {
            return null;
        }

        $leaveRequestId = $data['leave_request_id'] ?? null;
        if (! is_string($leaveRequestId) || $leaveRequestId === '' || ! Str::isUuid($leaveRequestId)) {
            return null;
        }

        $leaveRequest = LeaveRequest::query()
            ->with([
                'employee',
                'jenisCuti',
            ])
            ->find($leaveRequestId);
        if ($leaveRequest === null || $leaveRequest->employee === null) {
            return null;
        }

        $url = $this->resolveUrl($data['url'] ?? "/cuti/{$leaveRequest->id}");
        if ($url === null) {
            return null;
        }

        $namaPemohon = $this->privacy->sanitize($leaveRequest->employee->nama_lengkap);
        $jenisCuti = $this->privacy->sanitize($leaveRequest->jenisCuti?->nama ?? 'Cuti');
        $tanggalMulai = $this->formatDate($leaveRequest->tanggal_mulai);
        $tanggalSelesai = $this->formatDate($leaveRequest->tanggal_selesai);
        $jumlahHari = ((int) $leaveRequest->jumlah_hari_kerja).' hari kerja';

        // Kontrak template resmi menyertakan alasan pengajuan; alasan wajib terisi
        // karena form pengajuan mewajibkannya. Kosong berarti data tidak layak dikirim.
        $alasan = $this->privacy->sanitize($leaveRequest->alasan);
        if ($alasan === '') {
            return null;
        }

        $canonicalVariables = [
            'nama_pegawai' => $namaPemohon,
            'jenis_cuti' => $jenisCuti,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'jumlah_hari' => $jumlahHari,
            'alasan' => $alasan,
            'tautan_detail' => $url,
        ];

        $contract = $this->resolveTemplateContract($templateKey, $canonicalVariables, $url, WhatsAppTemplateContract::ARCHETYPE_CUTI_PERLU_TINDAKAN);
        if ($contract === null) {
            return null;
        }

        [$templateId, $language, $bodyVariables, $buttonVariables] = $contract;

        return new WhatsAppMappedTemplate(
            eventKey: $eventKey,
            templateKey: $templateKey,
            templateId: $templateId,
            language: $language,
            variables: $canonicalVariables,
            bodyVariables: $bodyVariables,
            buttonVariables: $buttonVariables,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapCutiStatus(string $eventKey, Employee $recipient, array $data): ?WhatsAppMappedTemplate
    {
        $eventTemplates = $this->runtime->eventTemplates();
        $templateKey = $eventTemplates[$eventKey] ?? null;
        if (! is_string($templateKey) || trim($templateKey) === '') {
            return null;
        }

        $leaveRequestId = $data['leave_request_id'] ?? null;
        $leaveRequest = is_string($leaveRequestId) && Str::isUuid($leaveRequestId)
            ? LeaveRequest::query()->with([
                'employee',
                'jenisCuti',
            ])->find($leaveRequestId)
            : null;

        $targetUrl = $eventKey === 'cuti.dikembalikan_karena_rollover'
            ? '/dashboard/cuti'
            : ($data['url'] ?? ($leaveRequest !== null ? "/cuti/{$leaveRequest->id}" : '/dashboard/cuti'));

        $url = $this->resolveUrl($targetUrl);
        if ($url === null) {
            return null;
        }

        $namaPemohon = $this->privacy->sanitize($leaveRequest?->employee?->nama_lengkap ?? $recipient->nama_lengkap);
        $jenisCuti = $this->privacy->sanitize($leaveRequest?->jenisCuti?->nama ?? 'Cuti Tahunan');

        $statusData = match ($eventKey) {
            'cuti.disetujui' => $this->resolveApprovalDecision($leaveRequest, $data, 'APPROVE', 'Disetujui', 'Permohonan cuti telah disetujui sesuai usulan.'),
            'cuti.ditunda' => $this->resolveApprovalDecision($leaveRequest, $data, 'POSTPONE', 'Ditangguhkan'),
            'cuti.perlu_perubahan' => $this->resolveApprovalDecision($leaveRequest, $data, 'REQUEST_CHANGES', 'Perubahan'),
            'cuti.tidak_disetujui' => $this->resolveApprovalDecision($leaveRequest, $data, 'NOT_APPROVED', 'Tidak Disetujui'),
            'cuti.ditangguhkan_tugas_dinas' => $this->resolveDutyPostponementDecision($leaveRequest, $data),
            'cuti.dikembalikan_karena_rollover' => $this->resolveRolloverReturnDecision($data),
            default => null,
        };

        if ($statusData === null) {
            return null;
        }

        $canonicalVariables = [
            'nama_pegawai' => $namaPemohon,
            'jenis_cuti' => $jenisCuti,
            'status' => $statusData['status'],
            'keterangan' => $statusData['keterangan'],
            'tautan_detail' => $url,
        ];

        $contract = $this->resolveTemplateContract($templateKey, $canonicalVariables, $url, WhatsAppTemplateContract::ARCHETYPE_CUTI_STATUS);
        if ($contract === null) {
            return null;
        }

        [$templateId, $language, $bodyVariables, $buttonVariables] = $contract;

        return new WhatsAppMappedTemplate(
            eventKey: $eventKey,
            templateKey: $templateKey,
            templateId: $templateId,
            language: $language,
            variables: $canonicalVariables,
            bodyVariables: $bodyVariables,
            buttonVariables: $buttonVariables,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, keterangan: string}|null
     */
    private function resolveApprovalDecision(
        ?LeaveRequest $leaveRequest,
        array $data,
        string $expectedAction,
        string $statusLabel,
        ?string $defaultKeterangan = null,
    ): ?array {
        if ($leaveRequest === null) {
            return null;
        }

        $approvalId = $data['leave_approval_id'] ?? null;
        if (! is_string($approvalId) || $approvalId === '') {
            return null;
        }

        $approval = LeaveApproval::query()
            ->whereKey($approvalId)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('action', $expectedAction)
            ->first();

        if ($approval === null) {
            return null;
        }

        $rawKomentar = $approval->komentar;
        $keterangan = $this->privacy->sanitize($rawKomentar);

        if ($keterangan === '') {
            if ($defaultKeterangan !== null) {
                $keterangan = $defaultKeterangan;
            } else {
                return null; // Keputusan penolakan/perubahan wajib memiliki catatan
            }
        }

        return [
            'status' => $statusLabel,
            'keterangan' => $keterangan,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, keterangan: string}|null
     */
    private function resolveDutyPostponementDecision(?LeaveRequest $leaveRequest, array $data): ?array
    {
        if ($leaveRequest === null) {
            return null;
        }

        $approvalId = $data['leave_approval_id'] ?? null;
        $query = LeaveApproval::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('action', 'DUTY_POSTPONEMENT');

        if (is_string($approvalId) && $approvalId !== '') {
            $query->whereKey($approvalId);
        }

        $approvals = $query->get();
        if ($approvals->count() !== 1) {
            return null;
        }

        $approval = $approvals->first();
        $rawReason = $approval?->komentar;
        $sanitizedReason = $this->privacy->sanitize($rawReason);

        if ($sanitizedReason === '') {
            return null;
        }

        return [
            'status' => 'Ditangguhkan',
            'keterangan' => sprintf(
                '%s — Hak cuti dilindungi dan reservasi saldo dilepas; silakan ajukan permohonan baru pada tahun berikutnya.',
                $sanitizedReason,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, keterangan: string}|null
     */
    private function resolveRolloverReturnDecision(array $data): ?array
    {
        $sourceYear = (int) ($data['source_year'] ?? 0);
        $targetYear = (int) ($data['target_year'] ?? 0);
        $count = $this->resolveRolloverRequestCount($data);

        if ($sourceYear <= 0 || $targetYear <= 0 || $count === null) {
            return null;
        }

        return [
            'status' => 'Perubahan',
            'keterangan' => sprintf(
                'Pengajuan cuti tahun %d (%d berkas) dikembalikan karena proses rollover saldo; silakan periksa daftar permohonan dan ajukan kembali pada tahun %d.',
                $sourceYear,
                $count,
                $targetYear,
            ),
        ];
    }

    /**
     * Mengambil jumlah berkas rollover dari payload Action yang menjadi sumber data.
     * Bila daftar ID tersedia, daftar itu lebih otoritatif daripada nilai ringkasan
     * agar pesan tidak salah menyebut satu berkas saat lebih dari satu dikembalikan.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveRolloverRequestCount(array $data): ?int
    {
        if (array_key_exists('leave_request_ids', $data)) {
            if (! is_array($data['leave_request_ids'])) {
                return null;
            }

            $requestIds = [];
            foreach ($data['leave_request_ids'] as $requestId) {
                if (! is_string($requestId) || trim($requestId) === '') {
                    return null;
                }

                $requestIds[$requestId] = true;
            }

            return $requestIds === [] ? null : count($requestIds);
        }

        $count = filter_var($data['jumlah_pengajuan'] ?? null, FILTER_VALIDATE_INT);

        return is_int($count) && $count > 0 ? $count : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapEwsPengingat(string $eventKey, Employee $recipient, array $data): ?WhatsAppMappedTemplate
    {
        $eventTemplates = $this->runtime->eventTemplates();
        $templateKey = $eventTemplates[$eventKey] ?? null;
        if (! is_string($templateKey) || trim($templateKey) === '') {
            return null;
        }

        $alertId = $data['ews_alert_id'] ?? null;
        if (! is_string($alertId) || $alertId === '' || ! Str::isUuid($alertId)) {
            return null;
        }

        $alert = EwsAlert::query()->with('employee')->whereKey($alertId)->first();
        if ($alert === null || $alert->employee === null) {
            return null;
        }

        // Pastikan tipe alert cocok dengan event
        $expectedTypes = match ($eventKey) {
            'ews.kenaikan_pangkat' => ['KENAIKAN_PANGKAT', 'kenaikan_pangkat'],
            'ews.kgb' => ['KGB', 'kgb'],
            'ews.pensiun' => ['PENSIUN', 'pensiun'],
            'ews.kontrak_pppk' => ['KONTRAK_PPPK', 'kontrak_pppk', 'pppk_contract_end'],
            'ews.satyalancana' => ['SATYALANCANA', 'satyalancana'],
            default => [],
        };

        if (! in_array(strtoupper($alert->type), array_map('strtoupper', $expectedTypes), true)) {
            return null; // Tipe EwsAlert tidak cocok dengan event EWS
        }

        // Database adalah sumber kebenaran kelayakan; payload tidak boleh membypass is_eligible false
        $isEligible = $alert->is_eligible !== false;

        $jenisPeringatan = match ($eventKey) {
            'ews.kenaikan_pangkat' => $isEligible ? 'Kenaikan Pangkat' : null,
            'ews.kgb' => 'Kenaikan Gaji Berkala (KGB)',
            'ews.pensiun' => 'Batas Usia Pensiun (BUP)',
            'ews.kontrak_pppk' => 'Kontrak PPPK',
            'ews.satyalancana' => $this->resolveSatyalancanaLabel($alert, $isEligible),
            default => null,
        };

        if ($jenisPeringatan === null) {
            return null;
        }

        $defaultUrl = (string) $alert->employee_id === (string) $recipient->id
            ? '/dashboard/ews-saya'
            : '/ews';
        $url = $this->resolveUrl($data['url'] ?? $defaultUrl);
        if ($url === null) {
            return null;
        }

        $namaPegawai = $this->privacy->sanitize($alert->employee->nama_lengkap);
        $tanggalTarget = $this->formatDate($alert->target_date);

        $targetDate = $alert->target_date instanceof Carbon ? $alert->target_date : Carbon::parse($alert->target_date);
        $diffDays = (int) Carbon::today()->diffInDays($targetDate->copy()->startOfDay(), false);
        if ($diffDays > 0) {
            $sisaWaktu = "H-{$diffDays} hari";
        } elseif ($diffDays === 0) {
            $sisaWaktu = 'Hari ini';
        } else {
            $sisaWaktu = 'Lewat jatuh tempo';
        }

        $canonicalVariables = [
            'nama_pegawai' => $namaPegawai,
            'jenis_peringatan' => $jenisPeringatan,
            'tanggal_target' => $tanggalTarget,
            'sisa_waktu' => $sisaWaktu,
            'tautan_detail' => $url,
        ];

        $contract = $this->resolveTemplateContract($templateKey, $canonicalVariables, $url, WhatsAppTemplateContract::ARCHETYPE_EWS_PENGINGAT);
        if ($contract === null) {
            return null;
        }

        [$templateId, $language, $bodyVariables, $buttonVariables] = $contract;

        return new WhatsAppMappedTemplate(
            eventKey: $eventKey,
            templateKey: $templateKey,
            templateId: $templateId,
            language: $language,
            variables: $canonicalVariables,
            bodyVariables: $bodyVariables,
            buttonVariables: $buttonVariables,
        );
    }

    private function resolveSatyalancanaLabel(EwsAlert $alert, bool $isEligible): ?string
    {
        $years = $alert->satyalancana_years;
        if (! in_array($years, [10, 20, 30], true)) {
            return null; // Milestone Satyalancana hanya 10, 20, 30
        }

        $suffix = $isEligible ? '' : ' (Kelayakan Masa Kerja Belum Terpenuhi)';

        return "Satyalancana Karya Satya {$years} Tahun{$suffix}";
    }

    private function formatDate(Carbon|string|null $date): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
        $day = str_pad((string) $carbon->day, 2, '0', STR_PAD_LEFT);
        $month = self::INDONESIAN_MONTHS[$carbon->month] ?? (string) $carbon->month;
        $year = $carbon->year;

        return "{$day} {$month} {$year}";
    }

    /**
     * Menyelesaikan kontrak runtime exact-provider (template ID, bahasa, variables_map, dan konfigurasi tombol).
     *
     * @param  array<string, string>  $canonicalVariables
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>}|null
     */
    private function resolveTemplateContract(
        string $templateKey,
        array $canonicalVariables,
        ?string $url,
        ?string $archetype = null,
    ): ?array {
        $templateConfig = $this->runtime->template($templateKey);
        if (! is_array($templateConfig)) {
            return null;
        }

        if (! WhatsAppTemplateContract::isConfigured($templateKey, $templateConfig, $archetype)
            || $url === null) {
            return null;
        }

        /** @var string $templateId */
        $templateId = $templateConfig['id'];
        /** @var string $language */
        $language = $templateConfig['language'];
        /** @var array<string, string> $variablesMap */
        $variablesMap = $templateConfig['variables_map'];
        $bodyVariables = [];

        foreach ($variablesMap as $canonicalKey => $providerKey) {
            // Tautan detail tidak pernah menjadi parameter body; ia disalurkan ke tombol URL.
            if ($canonicalKey === WhatsAppTemplateContract::BUTTON_ONLY_VARIABLE) {
                continue;
            }

            if (! array_key_exists($canonicalKey, $canonicalVariables)) {
                return null;
            }

            $bodyVariables[trim($providerKey)] = $canonicalVariables[$canonicalKey];
        }

        /** @var array{type: string, parameter: string} $buttonConfig */
        $buttonConfig = $templateConfig['button'];
        $buttonVariables = [trim($buttonConfig['parameter']) => $url];

        return [$templateId, $language, $bodyVariables, $buttonVariables];
    }

    /**
     * Memvalidasi tautan dengan basis canonical_url resmi yang ditetapkan LLDIKTI.
     * Mengembalikan null (fail-closed) bila canonical_url belum dikonfigurasi atau tautan tidak valid.
     */
    private function resolveUrl(?string $url): ?string
    {
        $canonicalUrl = $this->runtime->canonicalUrl();
        if (! is_string($canonicalUrl) || trim($canonicalUrl) === '') {
            return null; // Fail-closed: domain resmi SIMPEG belum ditetapkan LLDIKTI
        }

        $canonicalUrl = trim($canonicalUrl);
        $parsedCanonical = parse_url($canonicalUrl);
        $canonicalScheme = strtolower((string) ($parsedCanonical['scheme'] ?? ''));
        $canonicalHost = trim(strtolower((string) ($parsedCanonical['host'] ?? '')), '[]');

        // Domain resmi wajib HTTPS dan bukan loopback/localhost
        if ($canonicalScheme !== 'https' || $canonicalHost === '' || in_array($canonicalHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        if ($url === null || trim($url) === '') {
            $url = '/dashboard';
        }

        $url = trim($url);

        // Path relatif digabungkan dengan canonical HTTPS URL
        if (str_starts_with($url, '/')) {
            return rtrim($canonicalUrl, '/').'/'.ltrim($url, '/');
        }

        // Tolak skema tidak aman
        if (str_starts_with($url, 'http://')) {
            return null;
        }

        // Tautan absolut wajib HTTPS dan host wajib persis cocok dengan host domain resmi
        if (str_starts_with($url, 'https://')) {
            $parsed = parse_url($url);
            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            $host = trim(strtolower((string) ($parsed['host'] ?? '')), '[]');

            if ($scheme !== 'https' || $host !== $canonicalHost) {
                return null; // Tolak domain di luar domain resmi yang ditetapkan
            }

            return $url;
        }

        // Skema selain https ditolak fail-closed
        return null;
    }
}
