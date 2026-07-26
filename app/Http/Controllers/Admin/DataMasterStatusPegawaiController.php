<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreStatusPegawaiRequest;
use App\Http\Requests\Referensi\UpdateStatusPegawaiRequest;
use App\Models\RefStatusPegawai;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterStatusPegawaiController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreStatusPegawaiRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefStatusPegawai::class, $request->validated(), $request);

        return $this->backToTab($request, 'Status pegawai berhasil ditambahkan.');
    }

    public function update(UpdateStatusPegawaiRequest $request, RefStatusPegawai $statusPegawai, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($statusPegawai, $request->validated(), $request);

        return $this->backToTab($request, 'Status pegawai berhasil diperbarui.');
    }

    public function toggle(Request $request, RefStatusPegawai $statusPegawai, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($statusPegawai, $request);

        return $this->backToTab($request, $statusPegawai->is_active
            ? 'Status pegawai berhasil diaktifkan kembali.'
            : 'Status pegawai berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefStatusPegawai $statusPegawai, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($statusPegawai, $request);

        return $this->backToTab($request, 'Status pegawai berhasil dihapus.');
    }
}
