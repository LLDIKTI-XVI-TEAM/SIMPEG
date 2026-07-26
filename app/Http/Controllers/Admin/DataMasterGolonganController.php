<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreGolonganRequest;
use App\Http\Requests\Referensi\UpdateGolonganRequest;
use App\Models\RefGolongan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterGolonganController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreGolonganRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefGolongan::class, $request->validated(), $request);

        return $this->backToTab($request, 'Golongan berhasil ditambahkan.');
    }

    public function update(UpdateGolonganRequest $request, RefGolongan $golongan, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($golongan, $request->validated(), $request);

        return $this->backToTab($request, 'Golongan berhasil diperbarui.');
    }

    public function toggle(Request $request, RefGolongan $golongan, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($golongan, $request);

        return $this->backToTab($request, $golongan->is_active
            ? 'Golongan berhasil diaktifkan kembali.'
            : 'Golongan berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefGolongan $golongan, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($golongan, $request);

        return $this->backToTab($request, 'Golongan berhasil dihapus.');
    }
}
