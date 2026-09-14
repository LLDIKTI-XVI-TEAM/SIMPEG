<?php

namespace App\Livewire\Admin\Pegawai;

use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefProgramStudi;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Tambah Pegawai')]
class Create extends Component
{
    #[Locked]
    public bool $rbacSurface = false;

    public function mount(): void
    {
        $this->rbacSurface = request()->routeIs('rbac.pegawai.*');
    }

    public function render()
    {
        // Refresh Livewire tetap memerlukan permission terkini, bukan hanya izin pada GET awal.
        abort_unless(auth()->user()?->hasPermission('employees.create'), 403);
        $formActionUrl = route($this->rbacSurface ? 'rbac.pegawai.store' : 'pegawai.store');
        $returnUrl = route($this->rbacSurface ? 'dashboard' : 'data-pegawai');
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();
        $programStudiOptions = RefProgramStudi::where('is_active', true)->orderBy('nama')->get();

        return view('admin.pegawai.create', compact(
            'formActionUrl',
            'returnUrl',
            'jenisPegawai',
            'agama',
            'statusKawin',
            'unitKerja',
            'jabatanOptions',
            'jenisJabatanOptions',
            'golonganRefOptions',
            'eselonOptions',
            'programStudiOptions'
        ));
    }
}
