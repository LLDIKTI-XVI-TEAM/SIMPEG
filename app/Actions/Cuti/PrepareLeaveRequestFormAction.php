<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\RefJenisCuti;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveEligibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Menyusun view-model form pengajuan cuti bagi pemohon.
 *
 * Payload saldo tunggal digunakan oleh render awal Blade dan endpoint preview AJAX.
 * Dengan demikian form tidak mencampur kolom summary lama dengan angka validasi yang berbeda.
 */
class PrepareLeaveRequestFormAction
{
    public function __construct(
        private readonly PreviewLeaveBalanceAction $balancePreview,
        private readonly ApprovalChainResolver $chains,
        private readonly LeaveEligibilityService $eligibility,
    ) {}

    /**
     * Membentuk data form pengajuan cuti untuk seorang pegawai.
     *
     * @return array{
     *     employee: Employee,
     *     jenisCuti: Collection<int, RefJenisCuti>,
     *     saldoCuti: array<string, mixed>,
     *     isKepalaLembaga: bool,
     *     chainReady: bool,
     *     chainRoleLabels: array<int, string>,
     *     continuationLeaveCases: array<int, array{id: string, jenis_cuti_code: string, label: string}>,
     * }
     */
    public function execute(Employee $employee): array
    {
        // Preview awal memakai tanggal hari ini. Saat Pegawai memilih tanggal mulai lain,
        // JavaScript memanggil endpoint yang sama dengan tanggal acuan baru.
        $saldoCuti = $this->balancePreview->execute($employee, Carbon::now());

        // Metadata khusus_pns adalah sumber yang sama dengan validasi submit, bukan tebakan dari nama tampilan.
        $jenisCuti = RefJenisCuti::query()
            ->when($employee->jenisPegawai?->nama !== 'PNS', fn ($query) => $query->where('khusus_pns', false))
            ->orderBy('nama')
            ->get();

        // K-CUT-02: pemohon dapat memilih rangkaian miliknya sendiri untuk
        // melanjutkan Cuti Melahirkan/CLTN yang dipecah per tahun kalender.
        // View hanya menerima label ringkas; kecocokan pemilik dan jenis tetap
        // ditegakkan oleh service pada FormRequest dan Action.
        $continuationLeaveCases = $this->eligibility
            ->continuationCasesFor($employee)
            ->map(function ($leaveCase): array {
                $requests = $leaveCase->leaveRequests;
                $mulai = $requests->first()?->tanggal_mulai?->format('d-m-Y') ?? '-';
                $selesai = $requests->last()?->tanggal_selesai?->format('d-m-Y') ?? '-';

                return [
                    'id' => $leaveCase->id,
                    'jenis_cuti_code' => (string) $leaveCase->jenisCuti?->code,
                    'label' => sprintf('%s — periode tercatat %s s.d. %s', $leaveCase->jenisCuti?->nama, $mulai, $selesai),
                ];
            })
            ->values()
            ->all();

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
            'saldoCuti' => $saldoCuti,
            'isKepalaLembaga' => (bool) $employee->is_kepala_lembaga,
            'chainReady' => $chainReady,
            'chainRoleLabels' => $chainRoleLabels,
            'continuationLeaveCases' => $continuationLeaveCases,
        ];
    }
}
