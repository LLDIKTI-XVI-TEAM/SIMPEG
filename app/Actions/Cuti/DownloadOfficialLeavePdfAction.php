<?php

namespace App\Actions\Cuti;

use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveProofService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpFoundation\Response;

class DownloadOfficialLeavePdfAction
{
    public function __construct(private readonly LeaveProofService $proofs) {}

    /**
     * Memusatkan otorisasi rekam formulir resmi agar snapshot approver lama tetap berhak mengaksesnya.
     */
    public function canDownload(LeaveRequest $leaveRequest, ?User $user): bool
    {
        $leaveRequest->loadMissing(['proof', 'steps:id,leave_request_id,approver_employee_id']);

        return $this->canDownloadLoaded($leaveRequest, $user);
    }

    /** Mengevaluasi hak unduh setelah relasi minimum formulir resmi sudah tersedia. */
    private function canDownloadLoaded(LeaveRequest $leaveRequest, ?User $user): bool
    {
        if ($leaveRequest->status !== 'disetujui' || $leaveRequest->proof === null || $user === null) {
            return false;
        }

        if ($user->employee_id === $leaveRequest->employee_id) {
            return true;
        }

        if ($user->employee_id !== null && $leaveRequest->steps->contains('approver_employee_id', $user->employee_id)) {
            return true;
        }

        return $user->hasPermission('cuti.read_all');
    }

    /**
     * Menutup dokumen yang belum tersedia dengan 404 sebelum mengevaluasi otorisasi pemohon.
     * Urutan ini menghindari pengungkapan keberadaan formulir resmi yang belum diterbitkan.
     */
    public function execute(LeaveRequest $leaveRequest, ?User $user): Response
    {
        $leaveRequest->loadMissing(['proof', 'steps:id,leave_request_id,approver_employee_id']);

        if ($leaveRequest->status !== 'disetujui' || $leaveRequest->proof === null) {
            abort(404);
        }

        abort_unless($this->canDownloadLoaded($leaveRequest, $user), 403);

        $response = Pdf::loadView('admin.cuti.pdf.formulir-cuti', $this->viewData($leaveRequest))
            ->setPaper([0, 0, 612, 1008], 'portrait')
            ->download('Formulir_Cuti_'.$leaveRequest->id.'.pdf');

        // Formulir memuat PII pegawai; respons unduhan tidak boleh tersimpan di browser atau proxy bersama.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /**
     * Menyusun data formulir resmi sebagai scalar/array allow-list agar data pegawai sensitif tidak bocor ke Blade.
     *
     * @return array<string, mixed>
     */
    public function viewData(LeaveRequest $leaveRequest): array
    {
        $leaveRequest->load([
            'employee.appointments' => fn (HasMany $query) => $query
                ->whereNotNull('tmt_pengangkatan')
                ->orderBy('tmt_pengangkatan')
                ->orderBy('id')
                ->limit(1),
            'employee.positionHistories' => fn (HasMany $query) => $query
                ->where('is_latest', true)
                // Data legacy dapat memiliki lebih dari satu flag terkini; TMT lalu UUID menjaga formulir resmi konsisten.
                ->orderByDesc('tmt_jabatan')
                ->orderByDesc('id')
                ->limit(1)
                ->with('unitKerja:id,nama'),
            'employee.leaveBalances' => fn (HasMany $query) => $query
                ->where('tahun', $leaveRequest->tanggal_mulai->year),
            'jenisCuti',
            'proof',
            'steps' => fn (HasMany $query) => $query->orderBy('step_order'),
            'steps.approver',
        ]);

        $issuedAt = $this->issuedAt($leaveRequest->proof);
        $employee = $leaveRequest->employee;
        $serviceAppointment = $employee->appointments->first();
        $latestPosition = $employee->positionHistories->first();
        $balance = $employee->leaveBalances->first();
        $finalStep = $leaveRequest->steps
            ->where('is_final', true)
            ->where('status', 'approved')
            ->sortByDesc('step_order')
            ->first();
        $verificationUrl = $this->verificationUrl($leaveRequest->proof->token);

        // Data formulir memuat data pegawai sensitif; Blade hanya menerima nilai scalar/array allow-list ini.
        return [
            'institution' => 'LLDIKTI Wilayah XVI',
            'issuePlace' => 'Gorontalo',
            'issueDateLabel' => $this->dateLabel($issuedAt),
            'issueDateTimeLabel' => $this->dateTimeLabel($issuedAt),
            'employeeName' => $this->firstValue($employee->nama_dengan_gelar, $employee->nama_lengkap),
            'employeeNip' => $this->value($employee->nip),
            'employeePosition' => $this->value($latestPosition?->getRawOriginal('nama_jabatan')),
            'employeeServiceLength' => $this->serviceLength($serviceAppointment?->tmt_pengangkatan, $issuedAt),
            'employeeUnit' => $this->value($latestPosition?->unitKerja?->nama),
            'leaveTypeCode' => $this->value($leaveRequest->jenisCuti?->code),
            'leaveTypeName' => $this->value($leaveRequest->jenisCuti?->nama),
            'reason' => $this->value($leaveRequest->alasan),
            'startDateLabel' => $this->dateLabel($leaveRequest->tanggal_mulai),
            'endDateLabel' => $this->dateLabel($leaveRequest->tanggal_selesai),
            'workdayCount' => $leaveRequest->jumlah_hari_kerja,
            'addressDuringLeave' => $this->value($leaveRequest->alamat_selama_cuti),
            'phoneDuringLeave' => $this->value($leaveRequest->nomor_telepon),
            'balanceN2' => $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan && $balance !== null ? $balance->sisa_n2 : '-',
            'balanceN1' => $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan && $balance !== null ? $balance->sisa_n1 : '-',
            'balanceN' => $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan && $balance !== null ? $balance->sisa_tahun_berjalan : '-',
            'steps' => $leaveRequest->steps->map(fn ($step): array => [
                'order' => $step->step_order,
                'role' => $this->value($step->role_label),
                'approver' => $this->value($step->approver?->nama_lengkap),
                'statusLabel' => match ($step->status) {
                    'approved' => 'Disetujui',
                    'skipped' => 'Dilewati',
                    default => '-',
                },
                'note' => $this->value($step->decision_note),
                'actedAtLabel' => $step->acted_at === null ? '-' : $this->dateTimeLabel($step->acted_at),
            ])->all(),
            'finalApproverName' => $this->value($finalStep?->approver?->nama_lengkap),
            'finalApproverRole' => $this->value($finalStep?->role_label),
            'finalDecisionLabel' => $finalStep === null ? '-' : 'Disetujui',
            'finalActedAtLabel' => $finalStep?->acted_at === null ? '-' : $this->dateTimeLabel($finalStep->acted_at),
            'verificationUrl' => $verificationUrl,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($this->proofs->qrSvgForUrl($verificationUrl)),
        ];
    }

    /**
     * Mengambil waktu penerbitan dari bukti yang dibekukan tanpa mengganti data legacy dengan waktu akses.
     * Bukti dengan timestamp rusak tetap dapat diverifikasi, namun nilai penerbitan resmi harus ditampilkan sebagai fallback.
     */
    private function issuedAt(LeaveProof $proof): ?CarbonInterface
    {
        try {
            $generatedAt = $proof->generated_at;

            return $generatedAt?->toImmutable()->setTimezone('Asia/Makassar');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Memformat tanggal resmi dari snapshot bukti agar unduhan tidak bergantung pada jam akses. */
    private function dateLabel(?CarbonInterface $date): string
    {
        return $date?->locale('id')->translatedFormat('d F Y') ?? '-';
    }

    /** Memformat waktu resmi dalam WITA sebagai sumber tampilan dokumen LLDIKTI wilayah XVI. */
    private function dateTimeLabel(?CarbonInterface $date): string
    {
        return $date === null ? '-' : $date->locale('id')->translatedFormat('d F Y H:i').' WITA';
    }

    /**
     * Menghitung masa kerja dari pengangkatan non-null paling awal sampai waktu penerbitan bukti.
     * Riwayat pengangkatan adalah HasMany; pengurutan query menjaga sumber resmi deterministik tanpa HasOne arbitrer.
     */
    private function serviceLength(?CarbonInterface $appointmentDate, ?CarbonInterface $issuedAt): string
    {
        if ($appointmentDate === null || $issuedAt === null) {
            return '-';
        }

        $duration = $appointmentDate->diff($issuedAt);

        return "{$duration->y} tahun {$duration->m} bulan";
    }

    /**
     * Menyusun URL QR dari APP_URL tervalidasi, bukan Host request yang dapat dipalsukan oleh pemohon.
     * Jalur tetap berasal dari named route agar perubahan rute resmi tidak menghasilkan QR yang salah.
     */
    private function verificationUrl(string $token): string
    {
        $appUrl = config('app.url');

        if (! is_string($appUrl) || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new \LogicException('APP_URL harus berupa URL absolut yang valid untuk QR verifikasi cuti.');
        }

        $parts = parse_url($appUrl);
        if (! is_array($parts)
            || ! isset($parts['host'], $parts['scheme'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \LogicException('APP_URL harus memakai skema HTTP atau HTTPS dan host yang valid untuk QR verifikasi cuti.');
        }

        $relativePath = route('cuti.verify', $token, false);

        return rtrim($appUrl, '/').'/'.ltrim($relativePath, '/');
    }

    /** Menjaga seluruh leaf Blade sebagai teks scalar, dengan fallback untuk data kosong. */
    private function value(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '-';
        }

        $value = trim((string) $value);

        return $value === '' ? '-' : $value;
    }

    /** Memilih nama resmi bergelar bila ada, lalu nama lengkap, tanpa meneruskan nilai kosong ke formulir. */
    private function firstValue(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalizedValue = $this->value($value);

            if ($normalizedValue !== '-') {
                return $normalizedValue;
            }
        }

        return '-';
    }
}
