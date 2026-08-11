<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreProgramStudiRequest;
use App\Http\Requests\Referensi\UpdateProgramStudiRequest;
use App\Models\RefProgramStudi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterProgramStudiController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreProgramStudiRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefProgramStudi::class, $request->validated(), $request);

        return $this->backToTab($request, 'Program studi berhasil ditambahkan.');
    }

    public function update(UpdateProgramStudiRequest $request, RefProgramStudi $programStudi, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($programStudi, $request->validated(), $request);

        return $this->backToTab($request, 'Program studi berhasil diperbarui.');
    }

    public function toggle(Request $request, RefProgramStudi $programStudi, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($programStudi, $request);

        return $this->backToTab($request, $programStudi->is_active
            ? 'Program studi berhasil diaktifkan kembali.'
            : 'Program studi berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefProgramStudi $programStudi, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($programStudi, $request);

        return $this->backToTab($request, 'Program studi berhasil dihapus.');
    }
}
