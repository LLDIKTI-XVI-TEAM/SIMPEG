<?php

namespace App\Actions\Profiles;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\Documents\DocumentCategory;
use Illuminate\Support\Carbon;

class ShowProfilePageAction
{
    public function __construct(private readonly ListActiveEwsAlertsAction $ewsAlerts) {}

    public function execute(User $user): array
    {
        $employee = $user->employee()->with([
            'families',
            'rankHistories.golongan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'documents',
            'atasanLangsung',
        ])->first();

        $year = (int) now()->year;
        $leaveBalance = null;

        if ($employee !== null) {
            $leaveBalance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', $year)
                ->value('sisa');
        }

        return [
            'p' => $employee,
            'saldoCuti' => $leaveBalance,
            'tahun' => $year,
            'riwayatDokumen' => $employee?->documents?->map(fn ($document) => [
                'extension' => $document->fileExtension(),
                'nama' => $document->nama_dokumen,
                'keterangan' => $document->keterangan ?? '-',
                'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
                'nomor' => $document->nomor_dokumen ?? '-',
                'tanggal' => $document->tanggal_dokumen ? $document->tanggal_dokumen->format('Y-m-d') : '-',
                'file_size' => $document->fileSizeLabel(),
            ]) ?? [],
            'fotoUrl' => $employee?->foto_url,
            'estimasiPangkatNext' => $this->estimateNextRankDate($employee),
            'estimasiKgbNext' => $this->estimateNextKgbDate($employee),
            'estimasiPensiun' => $this->estimateRetirementDate($employee),
            'sisaPensiunStr' => $this->retirementRemainingLabel($employee),
            'ewsAlerts' => $employee
                ? $this->ewsAlerts->execute(null, null, (string) $employee->id)['alerts']
                : [],
        ];
    }

    private function estimateNextRankDate($employee): string
    {
        $latestRankDate = $employee?->latestRank()?->tmt_pangkat;

        return $latestRankDate
            ? Carbon::parse($latestRankDate)->addYears(4)->format('d-m-Y')
            : '-';
    }

    private function estimateNextKgbDate($employee): string
    {
        if ($employee?->tanggal_kgb_berikutnya) {
            return Carbon::parse($employee->tanggal_kgb_berikutnya)->format('d-m-Y');
        }

        $latestSalaryDate = $employee?->latestSalary()?->tmt_kgb;

        return $latestSalaryDate
            ? Carbon::parse($latestSalaryDate)->addYears(2)->format('d-m-Y')
            : '-';
    }

    private function estimateRetirementDate($employee): string
    {
        if (! $employee?->tanggal_pensiun) {
            return '-';
        }

        return Carbon::parse($employee->tanggal_pensiun)->format('d-m-Y');
    }

    private function retirementRemainingLabel($employee): string
    {
        if (! $employee?->tanggal_pensiun) {
            return '-';
        }

        $retirementDate = Carbon::parse($employee->tanggal_pensiun);

        if (! $retirementDate->isFuture()) {
            return 'Memasuki Usia Pensiun';
        }

        $diff = Carbon::now()->diff($retirementDate);

        return $diff->y.' Tahun, '.$diff->m.' Bulan lagi';
    }
}
