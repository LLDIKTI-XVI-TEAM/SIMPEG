<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreJabatanRequest;
use App\Http\Requests\Referensi\UpdateJabatanRequest;
use App\Models\RefJabatan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterJabatanController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreJabatanRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefJabatan::class, $request->validated(), $request);

        return $this->backToTab($request, 'Jabatan berhasil ditambahkan.');
    }

    public function update(UpdateJabatanRequest $request, RefJabatan $jabatan, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jabatan, $request->validated(), $request);

        return $this->backToTab($request, 'Jabatan berhasil diperbarui.');
    }

    public function toggle(Request $request, RefJabatan $jabatan, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($jabatan, $request);

        return $this->backToTab($request, $jabatan->is_active
            ? 'Jabatan berhasil diaktifkan kembali.'
            : 'Jabatan berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefJabatan $jabatan, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jabatan, $request);

        return $this->backToTab($request, 'Jabatan berhasil dihapus.');
    }
}
