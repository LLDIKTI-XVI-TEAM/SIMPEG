<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreJenjangPendidikanRequest;
use App\Http\Requests\Referensi\UpdateJenjangPendidikanRequest;
use App\Models\RefJenjangPendidikan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterJenjangPendidikanController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreJenjangPendidikanRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefJenjangPendidikan::class, $request->validated(), $request);

        return $this->backToTab($request, 'Jenjang pendidikan berhasil ditambahkan.');
    }

    public function update(UpdateJenjangPendidikanRequest $request, RefJenjangPendidikan $jenjang, UpdateReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jenjang, $request->validated(), $request);

        return $this->backToTab($request, 'Jenjang pendidikan berhasil diperbarui.');
    }

    public function toggle(Request $request, RefJenjangPendidikan $jenjang, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($jenjang, $request);

        return $this->backToTab($request, $jenjang->is_active
            ? 'Jenjang pendidikan berhasil diaktifkan kembali.'
            : 'Jenjang pendidikan berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefJenjangPendidikan $jenjang, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jenjang, $request);

        return $this->backToTab($request, 'Jenjang pendidikan berhasil dihapus.');
    }
}
