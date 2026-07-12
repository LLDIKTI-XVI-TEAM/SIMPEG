<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\RefJenisCuti;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Menyusun view-model form pengajuan cuti bagi pemohon.
 *
 * Angka saldo tersedia diambil dari mesin ledger (LeaveBalanceService), yaitu sumber yang sama
 * dengan validasi saldo saat submit; tujuannya agar tampilan dan penegakan aturan tidak pernah
 * memakai angka yang berbeda. Baris jatah/carry/terpakai hanya info tambahan, bukan angka otoritatif.
 */
class PrepareLeaveRequestFormAction
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly ApprovalChainResolver $chains,
    ) {}

    /**
     * Membentuk data form pengajuan cuti untuk seorang pegawai.
     *
     * @return array{
     *     employee: Employee,
     *     jenisCuti: Collection<int, RefJenisCuti>,
     *     saldoTahunan: LeaveBalance|null,
     *     saldoTersedia: int,
     *     isKepalaLembaga: bool,
     *     chainReady: bool,
     *     chainRoleLabels: array<int, string>,
     * }
     */
    public function execute(Employee $employee): array
    {
        $tahun = (int) now()->year;

        // Saldo tersedia otoritatif berasal dari ledger, konsisten dengan pemeriksaan saldo saat submit.
        $saldoTersedia = $this->balances->availableFor($employee, $tahun);

        // Baris summary tahunan dipakai hanya untuk info jatah/carry/terpakai, bukan sebagai angka otoritatif.
        $saldoTahunan = $employee->leaveBalances()->where('tahun', $tahun)->first();

        $jenisCuti = RefJenisCuti::orderBy('nama')->get();

        // Saat render form (GET), chain approval yang belum terkonfigurasi bukan kondisi fatal;
        // default belum siap, dan hanya ditandai siap bila resolver mengembalikan step yang benar-benar ada.
        // Penegakan fail-closed tetap dilakukan saat submit, sehingga penanganan longgar di sini tidak melemahkan keamanan pengajuan.
        $chainReady = false;
        $chainRoleLabels = [];

        try {
            $steps = $this->chains->resolveEffectiveSteps($employee);

            // Chain kosong tidak boleh dilaporkan siap, agar form tidak menyesatkan pemohon saat step belum lengkap.
            $chainReady = $steps->isNotEmpty();

            // Label peran diurutkan mengikuti urutan tahap approval sebenarnya (step_order).
            $chainRoleLabels = $steps
                ->sortBy('step_order')
                ->pluck('role_label')
                ->values()
                ->all();
        } catch (RuntimeException) {
            $chainReady = false;
        }

        return [
            'employee' => $employee,
            'jenisCuti' => $jenisCuti,
            'saldoTahunan' => $saldoTahunan,
            'saldoTersedia' => $saldoTersedia,
            'isKepalaLembaga' => (bool) $employee->is_kepala_lembaga,
            'chainReady' => $chainReady,
            'chainRoleLabels' => $chainRoleLabels,
        ];
    }
}
