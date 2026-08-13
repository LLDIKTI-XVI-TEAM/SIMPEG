<?php

namespace App\Livewire\Admin\Pegawai;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefUnitKerja;
use App\Support\Employees\EmployeeProfilePresentation;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Detail Pegawai')]
class Show extends Component
{
    public $pegawaiId;

    public function mount($id)
    {
        $this->pegawaiId = $id;
    }

    public function render()
    {
        $p = Employee::with([
            'families',
            'rankHistories.golongan',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'documents',
            'appointment',
            'agama',
            'statusKawin',
            'jenisPegawai',
            'statusPegawai',
            'statusHistories' => fn ($query) => $query
                ->orderByDesc('is_latest')
                ->orderByDesc('tanggal_efektif')
                ->orderByDesc('created_at')
                ->orderBy('id'),
            'supervisorAssignments.supervisor.positionHistories' => fn ($query) => $query->where('is_latest', true),
        ])->findOrFail($this->pegawaiId);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();

        $estimasiTanggalPensiun = EmployeeProfilePresentation::retirementDate($p);

        $currentSupervisor = $p->supervisorAssignments
            ->filter(fn ($assignment): bool => $assignment->tanggal_mulai->lte(today())
                && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte(today())))
            ->sortByDesc('tanggal_mulai')
            ->first();
        $currentSupervisorPosition = $currentSupervisor?->supervisor?->positionHistories
            ->where('is_latest', true)
            ->sortByDesc('tmt_jabatan')
            ->first();
        // Gunakan relasi yang sudah dimuat agar Blade tidak menjalankan query berulang saat merender profil.
        $latestRank = $p->rankHistories->firstWhere('is_latest', true);
        $latestPosition = $p->positionHistories->firstWhere('is_latest', true);
        $oldSupervisorId = old('kepala_bagian_id');
        $selectedSupervisor = is_string($oldSupervisorId) && Str::isUuid($oldSupervisorId)
            ? Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($oldSupervisorId)
            : null;
        $selectedSupervisorId = $selectedSupervisor?->id ?? $currentSupervisor?->supervisor?->id;
        $selectedSupervisorName = $selectedSupervisor?->nama_lengkap ?? $currentSupervisor?->supervisor?->nama_lengkap;

        EmployeeProfilePresentation::prepareStatusHistoryAttachments($p);

        // Snapshot status adalah sumber utama. Riwayat latest hanya menjadi fallback
        // untuk data lama yang belum memiliki status_tanggal tersinkron.
        $latestStatusHistory = $p->statusHistories->firstWhere('is_latest', true)
            ?? $p->statusHistories->sortByDesc('tanggal_efektif')->first();
        $statusPresentation = EmployeeProfilePresentation::status($p, $latestStatusHistory);

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jabatanOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions', 'jenjangOptions', 'estimasiTanggalPensiun', 'currentSupervisor', 'currentSupervisorPosition', 'latestRank', 'latestPosition', 'selectedSupervisorId', 'selectedSupervisorName', 'statusPresentation', 'latestStatusHistory'));
    }
}
