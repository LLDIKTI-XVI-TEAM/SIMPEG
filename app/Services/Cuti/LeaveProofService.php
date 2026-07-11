<?php

namespace App\Services\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Service penerbitan bukti cuti final.
 *
 * Bukti cuti adalah snapshot publik yang dibekukan saat pengajuan mencapai status final disetujui.
 * Snapshot sengaja hanya menyimpan data yang aman ditampilkan ke verifikator publik (nama, jenis cuti,
 * tanggal, jumlah hari, approver final, dan linimasa persetujuan). Data sensitif pegawai seperti NIP,
 * email, telepon, alamat, keluarga, gaji, serta komentar/decision approver tidak boleh ikut disimpan,
 * karena metadata ini nantinya dapat dibaca siapa pun yang memindai QR verifikasi.
 */
class LeaveProofService
{
    /**
     * Nama institusi yang dicantumkan pada bukti verifikasi publik.
     */
    private const INSTITUTION = 'LLDIKTI Wilayah XVI';

    /**
     * Batas panjang URL verifikasi yang wajar sebelum dikirim ke encoder QR,
     * untuk mencegah input tak terbatas membebani proses render.
     */
    private const MAX_QR_URL_LENGTH = 2048;

    /**
     * Batas percobaan pembuatan token untuk mengantisipasi tabrakan unik yang sangat jarang.
     */
    private const MAX_TOKEN_ATTEMPTS = 5;

    /**
     * Menerbitkan bukti cuti final secara idempoten untuk satu pengajuan.
     *
     * Idempotensi dijaga per leave_request_id (unik di database): jika bukti sudah ada, bukti lama
     * dikembalikan apa adanya tanpa token atau audit baru. Pembuatan bukti, token, dan audit penerbitan
     * berjalan dalam satu transaksi agar bukti tanpa jejak audit tidak pernah tersimpan.
     *
     * Parameter Employee penerbit dipakai sebagai konteks aktor (mis. relasi step approver), sedangkan
     * kolom FK generated_by hanya boleh diisi UUID user, bukan UUID employee, agar jejak akun tetap benar.
     */
    public function generateForApprovedRequest(LeaveRequest $leaveRequest, Employee $generatedByEmployee, ?User $generatedByUser = null): LeaveProof
    {
        // Guard fail-closed: bukti final hanya untuk pengajuan yang benar-benar sudah disetujui penuh.
        if ($leaveRequest->status !== 'disetujui') {
            throw ValidationException::withMessages([
                'status' => 'Bukti cuti hanya dapat diterbitkan untuk pengajuan yang sudah disetujui final.',
            ]);
        }

        // Fast-path idempoten di luar transaksi agar penerbitan berulang tidak membuka transaksi sia-sia.
        $existing = LeaveProof::query()->where('leave_request_id', $leaveRequest->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Relasi dimuat ulang agar snapshot dibangun dari data terbaru pengajuan, approver, dan langkah.
        $leaveRequest->loadMissing(['employee', 'jenisCuti', 'approvals.approver', 'steps.approver']);
        $metadata = $this->buildMetadata($leaveRequest);

        return DB::transaction(function () use ($leaveRequest, $generatedByEmployee, $generatedByUser, $metadata): LeaveProof {
            // Cek ulang di dalam transaksi agar dua penerbitan paralel tidak menghasilkan dua bukti.
            $existing = LeaveProof::query()->where('leave_request_id', $leaveRequest->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            // Satu stempel waktu penerbitan untuk seluruh panggilan ini, dipakai kolom dan metadata sekaligus
            // sehingga retry token tidak menggeser waktu dan kedua sumber selalu identik.
            $generatedAt = now();
            $metadata['generated_at'] = $generatedAt->toIso8601String();

            [$proof, $created] = $this->createProofWithUniqueToken($leaveRequest, $generatedByUser, $metadata, $generatedAt);

            // Audit penerbitan hanya ditulis untuk bukti yang benar-benar baru dibuat.
            if ($created) {
                $this->auditGeneration($proof, $leaveRequest, $generatedByEmployee, $generatedByUser);
            }

            return $proof;
        });
    }

    /**
     * Menyusun data tampilan publik hanya dari snapshot metadata bukti.
     *
     * Sengaja tidak membaca relasi/model mentah agar tampilan verifikasi tidak pernah membocorkan data
     * di luar snapshot yang sudah dibekukan. Allow-list ini menjadi batas privasi: metadata legacy atau kunci
     * yang tidak dikenal tidak boleh diteruskan ke tampilan publik. Token disertakan karena memang identitas
     * publik pada URL verifikasi. Menormalkan format tanggal dan status timeline agar tahan terhadap data malformed legacy.
     *
     * @return array<string, mixed>
     */
    public function publicViewData(LeaveProof $proof): array
    {
        // Salin metadata dan pastikan tipenya berupa array guna menepis risiko korupsi tipe data legacy.
        $metadata = $proof->metadata;
        if (! is_array($metadata)) {
            $metadata = [];
        }

        // Normalisasi data tanggal utama dengan format bahasa Indonesia.
        // Jika data kosong atau tidak valid, helper akan mengembalikan fallback '-'.
        $startDate = $metadata['start_date'] ?? null;
        $endDate = $metadata['end_date'] ?? null;
        $generatedAt = $metadata['generated_at'] ?? null;

        $startDateLabel = $this->formatPublicDate($startDate);
        $endDateLabel = $this->formatPublicDate($endDate);
        $generatedAtLabel = $this->formatPublicDate($generatedAt, true);

        // Menormalkan data final approver dan melindunginya dari tipe skalar/string tidak terduga.
        // Setiap leaf teks dilewatkan normalizePublicText agar container array/objek pada posisi skalar
        // tidak diteruskan mentah ke Blade e() (yang akan melempar TypeError dan membuat rute publik 500).
        $finalApprover = $metadata['final_approver'] ?? [];
        if (! is_array($finalApprover)) {
            $finalApprover = [];
        }
        $finalApproverName = $this->normalizePublicText($finalApprover['name'] ?? null);
        $finalApproverRole = $this->normalizePublicText($finalApprover['role'] ?? null);
        $finalApproverActedAt = $finalApprover['acted_at'] ?? null;
        $finalApproverActedAtLabel = $this->formatPublicDate($finalApproverActedAt, true);

        // Menormalkan data timeline persetujuan dan melindunginya jika bernilai skalar/string.
        $timeline = $metadata['approval_timeline'] ?? [];
        if (! is_array($timeline)) {
            $timeline = [];
        }

        $normalizedTimeline = [];
        foreach ($timeline as $step) {
            // Lewati entri timeline jika korup/tidak bertipe array (skalar).
            if (! is_array($step)) {
                continue;
            }

            // Setiap leaf timeline dinormalkan: order jatuh ke int aman, role/nama jatuh ke '-' bila korup,
            // status hanya menerima skalar aman dan selain itu menjadi kode kosong berlabel 'Tidak diketahui'.
            [$statusCode, $statusLabel] = $this->normalizeTimelineStatus($step['status'] ?? null);

            $normalizedTimeline[] = [
                'order' => $this->normalizeCount($step['order'] ?? 0),
                'role' => $this->normalizePublicText($step['role'] ?? null),
                'approver_name' => $this->normalizePublicText($step['approver_name'] ?? null),
                'status' => $statusCode,
                'status_label' => $statusLabel,
                'acted_at_label' => $this->formatPublicDate($step['acted_at'] ?? null, true),
            ];
        }

        return [
            'institution' => $this->normalizePublicText($metadata['institution'] ?? null, self::INSTITUTION, true),
            'employee_name' => $this->normalizePublicText($metadata['employee_name'] ?? null),
            'leave_type' => $this->normalizePublicText($metadata['leave_type'] ?? null),
            'workday_count' => $this->normalizeCount($metadata['workday_count'] ?? 0),
            'status_label' => $this->normalizePublicText($metadata['status_label'] ?? null, 'Disetujui', true),
            'token' => $proof->token,
            'start_date_label' => $startDateLabel,
            'end_date_label' => $endDateLabel,
            'generated_at_label' => $generatedAtLabel,
            'final_approver' => [
                'name' => $finalApproverName,
                'role' => $finalApproverRole,
                'acted_at_label' => $finalApproverActedAtLabel,
            ],
            'approval_timeline' => $normalizedTimeline,
        ];
    }

    /**
     * Menormalkan leaf teks publik dari snapshot metadata yang mungkin tercemar.
     *
     * Batas tipe korup: hanya string/int/float yang layak dicetak sebagai teks publik. Nilai
     * bool/array/objek/null dianggap leaf korup dan jatuh ke fallback, sehingga container array/objek
     * pada posisi skalar tidak pernah diteruskan mentah ke Blade e() (mencegah TypeError -> 500).
     * Bila $treatEmptyAsFallback true, teks kosong setelah trim juga jatuh ke fallback.
     */
    private function normalizePublicText(mixed $value, string $fallback = '-', bool $treatEmptyAsFallback = false): string
    {
        // Tolak semua tipe non-teks secara eksplisit; hanya string/int/float yang lolos.
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $fallback;
        }

        $text = (string) $value;

        if ($treatEmptyAsFallback && trim($text) === '') {
            return $fallback;
        }

        return $text;
    }

    /**
     * Menormalkan jumlah/urutan menjadi bilangan bulat non-negatif dari metadata yang mungkin tercemar.
     *
     * Batas tipe korup: int/float/string numerik dikonversi ke int lalu dinaikkan minimal 0; tipe lain
     * (array/objek/bool/string non-numerik/null) dianggap korup dan jatuh ke 0 agar tampilan tetap aman.
     */
    private function normalizeCount(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return max(0, (int) $value);
        }

        return 0;
    }

    /**
     * Menormalkan status langkah timeline menjadi pasangan [kode_status, label_status] yang aman.
     *
     * Batas tipe korup: hanya skalar yang boleh menjadi kode status; array/objek/null atau string kosong
     * dianggap tidak diketahui sehingga kode menjadi '' dan label 'Tidak diketahui'. Ini menjaga Blade
     * tetap menerima string skalar dan tidak pernah container yang memicu 500.
     *
     * @return array{0: string, 1: string}
     */
    private function normalizeTimelineStatus(mixed $status): array
    {
        if (! is_scalar($status)) {
            return ['', 'Tidak diketahui'];
        }

        $statusStr = trim((string) $status);

        if ($statusStr === '') {
            return ['', 'Tidak diketahui'];
        }

        return match ($statusStr) {
            'approved' => [$statusStr, 'Disetujui'],
            'rejected' => [$statusStr, 'Ditolak'],
            'pending' => [$statusStr, 'Menunggu'],
            default => [$statusStr, ucfirst($statusStr)],
        };
    }

    /**
     * Memformat tanggal publik secara aman dan tahan error dari masukan string/int malformed legacy.
     * Menggunakan WITA (Asia/Makassar) sebagai zona waktu default.
     */
    private function formatPublicDate(mixed $value, bool $withTime = false): string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return '-';
        }

        $valueStr = trim((string) $value);
        if ($valueStr === '') {
            return '-';
        }

        try {
            $carbon = Carbon::parse($valueStr)->setTimezone('Asia/Makassar');
            if ($withTime) {
                return $carbon->translatedFormat('d F Y H:i T');
            }

            return $carbon->translatedFormat('d F Y');
        } catch (\Throwable $e) {
            return '-';
        }
    }

    /**
     * Mencari bukti cuti berdasarkan token verifikasi atau gagal dengan 404.
     * Mengunci pencarian hanya pada pengajuan cuti yang berstatus 'disetujui' (final).
     *
     * @throws ModelNotFoundException
     */
    public function findApprovedByTokenOrFail(string $token): LeaveProof
    {
        return LeaveProof::query()
            ->where('token', $token)
            ->whereHas('leaveRequest', function ($query) {
                $query->where('status', 'disetujui');
            })
            ->firstOrFail();
    }

    /**
     * Merender QR SVG untuk URL verifikasi bukti.
     *
     * Batas kepercayaan: satu-satunya keluaran mentah yang boleh ditampilkan tanpa escaping adalah SVG
     * hasil package Bacon QR; URL divalidasi non-kosong dan dibatasi panjangnya sebelum di-encode, sehingga
     * QR hanya menyandikan URL (bukan teks literal yang ditampilkan ke pengguna).
     */
    public function qrSvgForUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw ValidationException::withMessages([
                'url' => 'URL verifikasi tidak boleh kosong untuk pembuatan QR.',
            ]);
        }

        if (strlen($url) > self::MAX_QR_URL_LENGTH) {
            throw ValidationException::withMessages([
                'url' => 'URL verifikasi terlalu panjang untuk pembuatan QR.',
            ]);
        }

        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);
        $writer = new Writer($renderer);

        return $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * Membuat baris bukti dengan token unik.
     *
     * Token acak 64 karakter praktis tidak mungkin bentrok, namun tetap dilindungi retry berbatas.
     * Bila tabrakan berasal dari leave_request_id (mis. penerbitan paralel), bukti pesaing diambil ulang
     * dan dikembalikan tanpa dianggap baru; QueryException di luar dua kasus itu tidak ditelan.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{0: LeaveProof, 1: bool}
     */
    private function createProofWithUniqueToken(LeaveRequest $leaveRequest, ?User $generatedByUser, array $metadata, Carbon $generatedAt): array
    {
        for ($attempt = 1; $attempt <= self::MAX_TOKEN_ATTEMPTS; $attempt++) {
            $token = Str::random(64);

            try {
                // INSERT dibungkus transaksi bersarang agar menjadi savepoint pada driver yang mendukung
                // (PostgreSQL/MySQL). Pada PostgreSQL, pelanggaran unik membatalkan seluruh transaksi hingga
                // di-rollback; dengan savepoint, kegagalan hanya membatalkan sampai savepoint sehingga transaksi
                // approval di luar tetap hidup dan bisa dipakai untuk retry maupun query pemulihan.
                $proof = DB::transaction(fn (): LeaveProof => LeaveProof::query()->create([
                    'leave_request_id' => $leaveRequest->id,
                    'token' => $token,
                    'document_path' => null,
                    'document_mime' => null,
                    // Hanya UUID user penerbit yang disimpan; jangan pernah UUID employee.
                    'generated_by' => $generatedByUser?->id,
                    'generated_at' => $generatedAt,
                    'metadata' => $metadata,
                ]));

                return [$proof, true];
            } catch (QueryException $e) {
                // Catch berada di luar transaksi bersarang: savepoint sudah di-rollback sebelum blok ini,
                // sehingga query pemulihan di bawah berjalan pada transaksi yang kembali sehat.

                // Penerbitan paralel: bukti untuk pengajuan ini sudah dibuat proses lain, kembalikan itu.
                $winner = LeaveProof::query()->where('leave_request_id', $leaveRequest->id)->first();

                if ($winner !== null) {
                    return [$winner, false];
                }

                // Tabrakan token yang masih dalam batas percobaan: ulangi dengan token baru.
                if ($this->isTokenCollision($e) && $attempt < self::MAX_TOKEN_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }

        // Secara praktis tidak tercapai; retry di atas selalu berhenti lewat return atau throw.
        throw ValidationException::withMessages([
            'token' => 'Gagal membuat token bukti cuti yang unik setelah beberapa percobaan.',
        ]);
    }

    /**
     * Menentukan apakah QueryException berasal dari pelanggaran unik pada kolom token.
     *
     * Deteksi portabel: PostgreSQL memakai SQLSTATE 23505 (unique_violation) dan SQLite dilaporkan sebagai
     * SQLSTATE 23000, keduanya terbaca lewat getCode(). Untuk menghindari salah klasifikasi terhadap
     * pelanggaran unik kolom lain (mis. leave_request_id), pesan driver juga wajib menyebut token.
     */
    private function isTokenCollision(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        // PostgreSQL 23505 sudah spesifik unique_violation. SQLite memetakan semua pelanggaran
        // integritas (unique, FK, not null) ke 23000, sehingga branch SQLite wajib menegaskan kata "unique"
        // agar pelanggaran non-unik tidak salah diklasifikasikan sebagai tabrakan token.
        $isUniqueViolation = $sqlState === '23505'
            || ($sqlState === '23000' && str_contains($message, 'unique'));

        if (! $isUniqueViolation) {
            return false;
        }

        // Pastikan tabrakan memang pada kolom token, bukan unique lain seperti leave_request_id.
        return str_contains($message, 'token');
    }

    /**
     * Menulis audit penerbitan bukti secara durable di dalam transaksi.
     *
     * Berbeda dari audit generik yang fire-and-forget, audit penerbitan bukti wajib: jika insert gagal,
     * exception membubung dan me-rollback baris bukti sehingga tidak ada bukti tanpa jejak. new_values
     * hanya memuat field jejak yang aman, tanpa komentar approver atau data mentah lain.
     *
     * user_name dipilih sebagai konteks aktor manusia: nama user penerbit bila ada, jika tidak jatuh ke
     * nama pegawai penerbit (mis. saat approval final tanpa akun user), lalu terakhir label sistem.
     * Ini hanya konteks aktor audit; FK generated_by tetap UUID user atau null, bukan identitas employee.
     */
    private function auditGeneration(LeaveProof $proof, LeaveRequest $leaveRequest, Employee $generatedByEmployee, ?User $generatedByUser): void
    {
        AuditLog::query()->create([
            'user_id' => $generatedByUser?->id,
            'user_name' => $generatedByUser?->name ?? $generatedByEmployee->nama_lengkap ?? 'Sistem SIMPEG',
            'event' => 'LEAVE_PROOF_GENERATED',
            'auditable_type' => 'LeaveProof',
            'auditable_id' => $proof->id,
            'old_values' => null,
            'new_values' => [
                'leave_request_id' => $leaveRequest->id,
                'employee_id' => $leaveRequest->employee_id,
                'token' => $proof->token,
                'generated_by' => $generatedByUser?->id,
                'generated_at' => $proof->generated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Membekukan snapshot publik dari pengajuan yang sudah final disetujui.
     *
     * Hanya field aman-publik yang disimpan. Nama pemohon memakai nama lengkap tanpa gelar/NIP,
     * dan linimasa persetujuan hanya mencatat peran, nama approver, status, serta waktu tindakan,
     * bukan komentar atau catatan keputusan approver.
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(LeaveRequest $leaveRequest): array
    {
        // Bila lebih dari satu step final berstatus approved, approver final yang sah adalah posisi rantai
        // terakhir, yaitu step_order tertinggi; pemilihan ini deterministik dan tidak bergantung urutan query DB.
        $finalStep = $leaveRequest->steps
            ->where('is_final', true)
            ->where('status', 'approved')
            ->sortByDesc('step_order')
            ->first();

        return [
            'institution' => self::INSTITUTION,
            'employee_name' => $leaveRequest->employee?->nama_lengkap,
            'leave_type' => $leaveRequest->jenisCuti?->nama,
            'start_date' => $leaveRequest->tanggal_mulai?->toDateString(),
            'end_date' => $leaveRequest->tanggal_selesai?->toDateString(),
            'workday_count' => (int) $leaveRequest->jumlah_hari_kerja,
            'status_label' => 'Disetujui',
            'final_approver' => $this->finalApproverSnapshot($finalStep),
            'approval_timeline' => $this->approvalTimeline($leaveRequest),
            // generated_at sengaja tidak diisi di sini; waktu penerbitan tunggal ditetapkan saat transaksi
            // agar kolom generated_at dan metadata memakai stempel waktu yang sama persis.
        ];
    }

    /**
     * Snapshot approver final: hanya peran, nama, dan waktu persetujuan yang aman ditampilkan.
     *
     * @return array<string, mixed>
     */
    private function finalApproverSnapshot(?LeaveRequestStep $finalStep): array
    {
        return [
            'name' => $finalStep?->approver?->nama_lengkap,
            'role' => $finalStep?->role_label,
            'acted_at' => $finalStep?->acted_at?->toIso8601String(),
        ];
    }

    /**
     * Linimasa persetujuan publik-aman berurutan berdasarkan langkah snapshot pengajuan.
     *
     * @return list<array<string, mixed>>
     */
    private function approvalTimeline(LeaveRequest $leaveRequest): array
    {
        return $leaveRequest->steps
            ->sortBy('step_order')
            ->map(fn (LeaveRequestStep $step): array => [
                'order' => (int) $step->step_order,
                'role' => $step->role_label,
                'approver_name' => $step->approver?->nama_lengkap,
                'status' => $step->status,
                'acted_at' => $step->acted_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
