<?php

namespace App\Services;

use App\Models\ApprovalConfig;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin persetujuan cuti tiga tahap.
 *
 * Otorisasi inti bersifat person-based: pengguna yang bertindak harus benar-benar approver
 * yang berlaku untuk tahap yang sedang menunggu, bukan sekadar pemegang permission. Permission
 * route hanya gerbang kasar. Tahap yang menunggu diturunkan dari status pengajuan (dan dari
 * riwayat approval saat status Ditunda), sehingga tidak perlu menambah kolom current_stage.
 */
class LeaveApprovalService
{
    /** Pemetaan tahap approval ke status pengajuan yang merepresentasikan tahap tersebut sedang menunggu. */
    private const STAGE_STATUS = [
        1 => 'Menunggu Atasan Langsung',
        2 => 'Menunggu Verifikator',
        3 => 'Menunggu Pimpinan',
    ];

    private const STATUS_DISETUJUI = 'Disetujui';

    private const STATUS_DITUNDA = 'Ditunda';

    private const STAGE_FINAL = 3;

    /**
     * Menyetujui pengajuan pada tahap yang sedang menunggu.
     * Bila approver tahap berikutnya adalah orang yang sama, tahap itu dilewati otomatis (skip duplikat).
     * Saat seluruh tahap terpenuhi, status menjadi Disetujui dan saldo dipotong khusus Cuti Tahunan.
     */
    public function approve(LeaveRequest $leaveRequest, Employee $actor, ?string $komentar = null): LeaveRequest
    {
        // Pemeriksaan awal di luar transaksi memberi gagal-cepat yang ramah bagi approver yang jelas tidak berwenang.
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar) {
            // Baris pengajuan dikunci dan tahapnya diturunkan ulang di dalam transaksi agar dua persetujuan
            // bersamaan atas pengajuan yang sama tidak diproses dua kali (mencegah pemotongan saldo ganda).
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $stage = $this->pendingStageOrFail($locked);
            $this->assertActorIsApprover($locked, $actor, $stage);

            $this->recordApproval($locked, $actor, $stage, 'APPROVE', $komentar);

            // Tentukan tahap menunggu berikutnya sambil melewati tahap yang approver-nya sama dengan
            // approver saat ini, sesuai aturan skip duplikat pada alur cuti.
            $nextStage = $this->resolveNextStage($locked, $actor, $stage);

            if ($nextStage !== null) {
                $locked->status = self::STAGE_STATUS[$nextStage];
                $locked->save();

                return $locked;
            }

            // Seluruh tahap terpenuhi: finalkan dan potong saldo bila jenisnya memotong saldo.
            $locked->status = self::STATUS_DISETUJUI;
            $locked->save();

            $this->deductBalanceIfRequired($locked);

            return $locked;
        });
    }

    /**
     * Menunda pengajuan pada tahap yang sedang menunggu.
     * Penundaan bersifat reversible: approver yang sama dapat menyetujui kembali untuk melanjutkan alur,
     * karena tahap menunggu tetap dapat diturunkan dari riwayat approval terakhir.
     */
    public function postpone(LeaveRequest $leaveRequest, Employee $actor, string $komentar): LeaveRequest
    {
        // Gagal-cepat di luar transaksi untuk approver yang jelas tidak berwenang.
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar) {
            // Kunci dan turunkan ulang tahap di dalam transaksi agar penundaan bersamaan tidak berlomba dengan persetujuan.
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $stage = $this->pendingStageOrFail($locked);
            $this->assertActorIsApprover($locked, $actor, $stage);

            $this->recordApproval($locked, $actor, $stage, 'POSTPONE', $komentar);

            // Saldo tidak pernah dipotong saat penundaan; pemotongan hanya terjadi pada persetujuan final.
            $locked->status = self::STATUS_DITUNDA;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Menentukan tahap yang sedang menunggu tindakan approver.
     * Untuk status menunggu, tahap dipetakan langsung dari status. Untuk status Ditunda, tahap diambil
     * dari approval terakhir sehingga approver yang menunda dapat menindaklanjuti tahap yang sama.
     */
    public function pendingStage(LeaveRequest $leaveRequest): ?int
    {
        $stageByStatus = array_search($leaveRequest->status, self::STAGE_STATUS, true);

        if ($stageByStatus !== false) {
            return (int) $stageByStatus;
        }

        if ($leaveRequest->status === self::STATUS_DITUNDA) {
            // Urutkan menurun pada acted_at lalu stage sebagai pemecah seri, agar bila ada beberapa tindakan
            // dengan waktu sama (mis. persetujuan otomatis skip duplikat), tahap tertinggi yang diambil.
            $latest = $leaveRequest->approvals()
                ->orderByDesc('acted_at')
                ->orderByDesc('stage')
                ->first();

            return $latest?->stage;
        }

        return null;
    }

    /**
     * Mengembalikan employee_id approver untuk tahap tertentu.
     * Stage 1 = atasan langsung aktif pemohon; stage 2 dan 3 = approver terkonfigurasi (disimpan sebagai
     * id User) yang dijembatani ke employee_id agar konsisten dengan pencatatan approval berbasis pegawai.
     */
    public function approverEmployeeIdForStage(LeaveRequest $leaveRequest, int $stage): ?string
    {
        if ($stage === 1) {
            return $leaveRequest->employee?->currentSupervisor()?->supervisor?->id;
        }

        return $this->configuredApproverEmployeeId($stage);
    }

    /**
     * Menentukan apakah rantai approval (stage 2 dan stage 3) sudah dikonfigurasi dan dapat di-resolve
     * ke pegawai. Dipakai sebagai prasyarat operasional: tanpa approver terkonfigurasi, pengajuan tidak
     * boleh masuk antrean karena tidak akan ada yang dapat menindaklanjuti dan pengajuan akan tersangkut.
     */
    public function approvalChainIsConfigured(): bool
    {
        return $this->configuredApproverEmployeeId(2) !== null
            && $this->configuredApproverEmployeeId(3) !== null;
    }

    /**
     * Mengembalikan employee_id approver terkonfigurasi untuk stage 2 atau stage 3 dari approval_configs.
     * Konfigurasi menyimpan id User, lalu dijembatani ke employee_id agar konsisten dengan pencatatan approval.
     */
    private function configuredApproverEmployeeId(int $stage): ?string
    {
        $configKey = $stage === 2 ? 'stage2_approver_id' : 'stage3_approver_id';
        $userId = ApprovalConfig::getVal($configKey);

        if ($userId === null) {
            return null;
        }

        return User::find($userId)?->employee_id;
    }

    /** Menggagalkan operasi bila pengajuan tidak berada pada tahap yang dapat ditindak. */
    private function pendingStageOrFail(LeaveRequest $leaveRequest): int
    {
        $stage = $this->pendingStage($leaveRequest);

        if ($stage === null) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan cuti ini tidak sedang menunggu persetujuan, sehingga tidak dapat ditindak.',
            ]);
        }

        return $stage;
    }

    /** Memastikan pengguna yang bertindak adalah approver yang sah untuk tahap tersebut (otorisasi person-based). */
    private function assertActorIsApprover(LeaveRequest $leaveRequest, Employee $actor, int $stage): void
    {
        $approverId = $this->approverEmployeeIdForStage($leaveRequest, $stage);

        // Approver belum dapat di-resolve berarti rantai approval belum dikonfigurasi (mis. stage 2/3 kosong).
        // Bedakan dari kasus "bukan approver" agar approver yang sah tidak menerima pesan yang menyesatkan.
        if ($approverId === null) {
            throw ValidationException::withMessages([
                'status' => 'Konfigurasi approver cuti belum lengkap. Hubungi Super Admin.',
            ]);
        }

        if ($approverId !== $actor->id) {
            throw new AuthorizationException('Anda bukan approver yang berwenang untuk tahap persetujuan ini.');
        }
    }

    /** Mencatat satu transisi approval (APPROVE/POSTPONE) sebagai jejak riwayat dan bahan timeline. */
    private function recordApproval(LeaveRequest $leaveRequest, Employee $actor, int $stage, string $action, ?string $komentar): void
    {
        LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $actor->id,
            'stage' => $stage,
            'action' => $action,
            'komentar' => $komentar,
            'acted_at' => Carbon::now(),
        ]);
    }

    /**
     * Mencari tahap menunggu berikutnya setelah tahap saat ini.
     * Tahap yang approver-nya sama dengan approver saat ini dicatat sebagai persetujuan otomatis lalu dilewati,
     * agar satu orang tidak perlu menyetujui dua kali. Mengembalikan null bila semua tahap berikutnya terpenuhi.
     */
    private function resolveNextStage(LeaveRequest $leaveRequest, Employee $actor, int $currentStage): ?int
    {
        for ($stage = $currentStage + 1; $stage <= self::STAGE_FINAL; $stage++) {
            $approverId = $this->approverEmployeeIdForStage($leaveRequest, $stage);

            if ($approverId !== null && $approverId === $actor->id) {
                // Approver tahap ini sama dengan approver sebelumnya; catat persetujuan otomatis lalu lanjut.
                $this->recordApproval($leaveRequest, $actor, $stage, 'APPROVE', 'Disetujui otomatis karena approver sama dengan tahap sebelumnya.');

                continue;
            }

            return $stage;
        }

        return null;
    }

    /**
     * Memotong saldo cuti tahunan saat persetujuan final.
     * Hanya Cuti Tahunan yang memotong saldo. Baris saldo dikunci (lockForUpdate) dan diperiksa ulang agar
     * pemotongan bebas dari kondisi balapan antar-pengajuan yang menunggu di tahun yang sama.
     */
    private function deductBalanceIfRequired(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->jenisCuti?->nama !== 'Cuti Tahunan') {
            return;
        }

        $tahun = $leaveRequest->tanggal_mulai->year;
        $hari = (int) $leaveRequest->jumlah_hari_kerja;

        $balance = LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('tahun', $tahun)
            ->lockForUpdate()
            ->first();

        // Tanpa baris saldo tahun berjalan, saldo dianggap nol sehingga persetujuan tidak boleh menambah pemakaian negatif.
        $sisa = (int) ($balance?->sisa ?? 0);

        if ($balance === null || $sisa < $hari) {
            throw ValidationException::withMessages([
                'status' => "Saldo cuti tahunan tidak mencukupi saat persetujuan final. Sisa {$sisa} hari, dibutuhkan {$hari} hari.",
            ]);
        }

        $balance->terpakai += $hari;
        $balance->sisa = $sisa - $hari;
        $balance->save();
    }
}
