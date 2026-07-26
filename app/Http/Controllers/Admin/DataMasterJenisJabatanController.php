<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreJenisJabatanRequest;
use App\Http\Requests\Referensi\UpdateJenisJabatanRequest;
use App\Models\RefJenisJabatan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterJenisJabatanController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreJenisJabatanRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefJenisJabatan::class, $request->validated(), $request);

        return $this->backToTab($request, 'Jenis jabatan berhasil ditambahkan.');
    }

    public function update(UpdateJenisJabatanRequest $request, RefJenisJabatan $jenisJabatan, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jenisJabatan, $request->validated(), $request);

        return $this->backToTab($request, 'Jenis jabatan berhasil diperbarui.');
    }

    public function toggle(Request $request, RefJenisJabatan $jenisJabatan, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($jenisJabatan, $request);

        return $this->backToTab($request, $jenisJabatan->is_active
            ? 'Jenis jabatan berhasil diaktifkan kembali.'
            : 'Jenis jabatan berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefJenisJabatan $jenisJabatan, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jenisJabatan, $request);

        return $this->backToTab($request, 'Jenis jabatan berhasil dihapus.');
    }
}
