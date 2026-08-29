<?php

namespace App\Actions\Profiles;

use App\Actions\Cuti\PreviewLeaveBalanceAction;
use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\User;
use App\Support\Documents\DocumentCategory;
use Illuminate\Support\Carbon;

class ShowProfilePageAction
{
    public function __construct(
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
        private readonly PreviewLeaveBalanceAction $balancePreview,
    ) {}

    public function execute(User $user): array
    {
        $employee = $user->employee()->with([
            'families',
            'rankHistories.golongan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'educationHistories.programStudi',
            'programStudi',
            'documents',
            'kepalaBagian',
        ])->first();

        $year = (int) now()->year;
        $leaveBalance = null;
        $rule5Active = false;

        if ($employee !== null) {
            $leaveBalance = $this->balancePreview->execute($employee, Carbon::now());
            $rule5Active = $leaveBalance['rule_5_active'];
        }

        $ews = $employee !== null
            ? $this->ewsAlerts->preview(3, employeeId: (string) $employee->id)
            : ['alerts' => [], 'total' => 0, 'urgent' => 0, 'warning' => 0, 'info' => 0];

        return [
            'p' => $employee,
            'saldoCuti' => $leaveBalance,
            'rule5Active' => $rule5Active,
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
            'ewsAlerts' => $ews['alerts'],
            'ewsTotal' => $ews['total'],
            'ewsBuckets' => [
                'urgent' => $ews['urgent'],
                'warning' => $ews['warning'],
                'info' => $ews['info'],
            ],
        ];
    }

    // Estimasi pangkat dan KGB memakai snapshot kalkulator tersimpan, bukan rumus ulang dari riwayat.
    private function estimateNextRankDate($employee): string
    {
        return $employee?->tanggal_kenaikan_pangkat_berikutnya
            ? Carbon::parse($employee->tanggal_kenaikan_pangkat_berikutnya)->format('d-m-Y')
            : '-';
    }

    private function estimateNextKgbDate($employee): string
    {
        return $employee?->tanggal_kgb_berikutnya
            ? Carbon::parse($employee->tanggal_kgb_berikutnya)->format('d-m-Y')
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
