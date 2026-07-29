<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateUnitKerjaAction;
use App\Actions\Referensi\DeleteUnitKerjaAction;
use App\Actions\Referensi\ToggleUnitKerjaActiveAction;
use App\Actions\Referensi\UpdateUnitKerjaAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreUnitKerjaRequest;
use App\Http\Requests\Referensi\UpdateUnitKerjaRequest;
use App\Models\RefUnitKerja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterUnitKerjaController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreUnitKerjaRequest $request, CreateUnitKerjaAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request);

        return $this->backToTab($request, 'Unit kerja berhasil ditambahkan.');
    }

    public function update(UpdateUnitKerjaRequest $request, RefUnitKerja $unitKerja, UpdateUnitKerjaAction $action): RedirectResponse
    {
        $action->execute($unitKerja, $request->validated(), $request);

        return $this->backToTab($request, 'Unit kerja berhasil diperbarui.');
    }

    public function toggle(Request $request, RefUnitKerja $unitKerja, ToggleUnitKerjaActiveAction $action): RedirectResponse
    {
        $action->execute($unitKerja, $request);

        return $this->backToTab($request, $unitKerja->is_active
            ? 'Unit kerja berhasil diaktifkan kembali.'
            : 'Unit kerja berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefUnitKerja $unitKerja, DeleteUnitKerjaAction $action): RedirectResponse
    {
        $action->execute($unitKerja, $request);

        return $this->backToTab($request, 'Unit kerja berhasil dihapus.');
    }
}
