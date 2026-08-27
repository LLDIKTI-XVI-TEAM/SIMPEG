<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequestCase;
use App\Models\RefJenisCuti;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveEligibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menyusun view-model form pengajuan cuti bagi pemohon.
 *
 * Payload saldo tunggal digunakan oleh render awal Blade dan endpoint preview AJAX.
 * Dengan demikian form tidak mencampur kolom summary lama dengan angka validasi yang berbeda.
 */
class PrepareLeaveRequestFormAction
{
    /** @var list<string> */
    private const OFFICIAL_LEAVE_TYPE_CODES = [
        'tahunan',
        'sakit',
        'melahirkan',
        'alasan_penting',
        'besar',
        'cltn',
    ];

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
     *     leaveTypeCodes: array<string, string>,
     *     saldoCuti: array<string, mixed>,
     *     isKepalaLembaga: bool,
     *     chainReady: bool,
     *     chainRoleLabels: array<int, string>,
     *     continuationLeaveCases: array<int, array{id: string, jenis_cuti_code: string, label: string}>,
     * }
     */
    public function execute(
        Employee $employee,
        ?string $selectedLeaveTypeId = null,
        ?string $selectedLeaveCaseId = null,
    ): array {
        // Preview awal memakai tanggal hari ini. Saat Pegawai memilih tanggal mulai lain,
        // JavaScript memanggil endpoint yang sama dengan tanggal acuan baru.
        $saldoCuti = $this->balancePreview->execute($employee, Carbon::now());

        // Metadata khusus_pns adalah sumber yang sama dengan validasi submit, bukan tebakan dari nama tampilan.
        $jenisCutiQuery = RefJenisCuti::query()
            ->when($employee->jenisPegawai?->nama !== 'PNS', fn ($query) => $query->where('khusus_pns', false))
            // Jenis resmi selalu didahulukan. Nilai lama yang valid juga
            // diprioritaskan agar tetap tersedia setelah validasi gagal.
            ->orderByRaw(
                'CASE WHEN id = ? THEN 0 WHEN code IN (?, ?, ?, ?, ?, ?) THEN 1 ELSE 2 END',
                [
                    Str::isUuid((string) $selectedLeaveTypeId) ? $selectedLeaveTypeId : null,
                    ...self::OFFICIAL_LEAVE_TYPE_CODES,
                ],
            )
            ->orderBy('nama')
            ->limit(LeaveEligibilityService::FORM_OPTION_LIMIT);

        $jenisCuti = $jenisCutiQuery->get([
            'id',
            'nama',
            'code',
            'khusus_pns',
        ]);
        $leaveTypeCodes = $jenisCuti
            ->mapWithKeys(fn (RefJenisCuti $leaveType): array => [$leaveType->id => (string) $leaveType->code])
            ->all();

        // Pemohon dapat memilih rangkaian miliknya sendiri untuk melanjutkan
        // Cuti Melahirkan/CLTN yang dipecah per tahun kalender.
        // View hanya menerima label ringkas; kecocokan pemilik dan jenis tetap
        // ditegakkan oleh service pada FormRequest dan Action.
        $continuationLeaveCases = $this->eligibility
            ->continuationCasesFor($employee, $selectedLeaveCaseId)
            ->map(function (LeaveRequestCase $leaveCase): array {
                $mulai = $this->casePeriodBoundary($leaveCase, [
                    'request_period_start',
                    'manual_period_start',
                ], 'min');
                $selesai = $this->casePeriodBoundary($leaveCase, [
                    'request_period_end',
                    'manual_period_end',
                ], 'max');

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
            'leaveTypeCodes' => $leaveTypeCodes,
            'saldoCuti' => $saldoCuti,
            'isKepalaLembaga' => (bool) $employee->is_kepala_lembaga,
            'chainReady' => $chainReady,
            'chainRoleLabels' => $chainRoleLabels,
            'continuationLeaveCases' => $continuationLeaveCases,
        ];
    }

    /**
     * Menggabungkan batas periode dari request dan fakta manual tanpa memuat
     * seluruh relasi histori ke memori proses render.
     *
     * @param  list<string>  $attributes
     */
    private function casePeriodBoundary(LeaveRequestCase $leaveCase, array $attributes, string $mode): string
    {
        $dates = collect($attributes)
            ->map(fn (string $attribute): ?string => $leaveCase->getAttribute($attribute) !== null
                ? (string) $leaveCase->getAttribute($attribute)
                : null)
            ->filter()
            ->values();

        if ($dates->isEmpty()) {
            return '-';
        }

        $boundary = $mode === 'min' ? $dates->min() : $dates->max();

        return Carbon::parse((string) $boundary)->format('d-m-Y');
    }
}
