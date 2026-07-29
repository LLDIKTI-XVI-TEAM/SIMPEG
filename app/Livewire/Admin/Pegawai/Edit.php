<?php

namespace App\Livewire\Admin\Pegawai;

use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Edit Pegawai')]
class Edit extends Component
{
    public $pegawaiId;

    public function mount($id)
    {
        $this->pegawaiId = $id;
    }

    public function render(PrepareEmployeeEditFormDataAction $action)
    {
        $data = $action->execute($this->pegawaiId);

        return view('admin.pegawai.edit', $data);
    }
}
