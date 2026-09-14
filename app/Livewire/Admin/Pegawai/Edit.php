<?php

namespace App\Livewire\Admin\Pegawai;

use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Edit Pegawai')]
class Edit extends Component
{
    #[Locked]
    public $pegawaiId;

    #[Locked]
    public bool $rbacSurface = false;

    public function mount($id)
    {
        $this->pegawaiId = $id;
        $this->rbacSurface = request()->routeIs('rbac.pegawai.*');
    }

    public function render(PrepareEmployeeEditFormDataAction $action)
    {
        $data = $action->execute($this->pegawaiId, $this->rbacSurface);
        $data['formActionUrl'] = route($this->rbacSurface ? 'rbac.pegawai.update' : 'pegawai.update', $this->pegawaiId);
        $data['returnUrl'] = route($this->rbacSurface ? 'dashboard' : 'data-pegawai');

        return view('admin.pegawai.edit', $data);
    }
}
