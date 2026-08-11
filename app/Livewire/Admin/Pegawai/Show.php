<?php

namespace App\Livewire\Admin\Pegawai;

use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\RefUnitKerja;
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
            'rankHistories',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'educationHistories.programStudi',
            'documents',
            'agama',
            'statusKawin',
            'jenisPegawai',
            'statusPegawai',
            'programStudi',
            'supervisorAssignments.supervisor.positionHistories' => fn ($query) => $query->where('is_latest', true),
        ])->findOrFail($this->pegawaiId);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();
        $programStudiOptions = RefProgramStudi::where('is_active', true)->orderBy('nama')->get();

        // Prioritaskan tanggal_pensiun manual jika diset, fallback ke kalkulasi BUP
        $estimasiTanggalPensiun = $p->tanggal_pensiun;
        if ($estimasiTanggalPensiun === null) {
            $bupPensiunYears = max(0, (int) EwsConfig::getVal('pensiun_required_age_years', 0));
            $estimasiTanggalPensiun = $bupPensiunYears > 0 && $p->tanggal_lahir
                ? $p->tanggal_lahir->copy()->addYears($bupPensiunYears)
                : null;
        }

        $currentSupervisor = $p->supervisorAssignments
            ->filter(fn ($assignment): bool => $assignment->tanggal_mulai->lte(today())
                && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte(today())))
            ->sortByDesc('tanggal_mulai')
            ->first();
        $currentSupervisorPosition = $currentSupervisor?->supervisor?->positionHistories
            ->where('is_latest', true)
            ->sortByDesc('tmt_jabatan')
            ->first();
        $oldSupervisorId = old('kepala_bagian_id');
        $selectedSupervisor = is_string($oldSupervisorId) && Str::isUuid($oldSupervisorId)
            ? Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($oldSupervisorId)
            : null;
        $selectedSupervisorId = $selectedSupervisor?->id ?? $currentSupervisor?->supervisor?->id;
        $selectedSupervisorName = $selectedSupervisor?->nama_lengkap ?? $currentSupervisor?->supervisor?->nama_lengkap;

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jabatanOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions', 'jenjangOptions', 'programStudiOptions', 'estimasiTanggalPensiun', 'currentSupervisor', 'currentSupervisorPosition', 'selectedSupervisorId', 'selectedSupervisorName'));
    }
}
