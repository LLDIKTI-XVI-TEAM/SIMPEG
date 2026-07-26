<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreEselonRequest;
use App\Http\Requests\Referensi\UpdateEselonRequest;
use App\Models\RefEselon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterEselonController extends Controller
{
    public function store(StoreEselonRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefEselon::class, $request->validated(), $request);

        return back()->with('success', 'Eselon berhasil ditambahkan.');
    }

    public function update(UpdateEselonRequest $request, RefEselon $eselon, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($eselon, $request->validated(), $request);

        return back()->with('success', 'Eselon berhasil diperbarui.');
    }

    public function toggle(Request $request, RefEselon $eselon, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($eselon, $request);

        return back()->with('success', $eselon->is_active
            ? 'Eselon berhasil diaktifkan kembali.'
            : 'Eselon berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefEselon $eselon, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($eselon, $request);

        return back()->with('success', 'Eselon berhasil dihapus.');
    }
}
