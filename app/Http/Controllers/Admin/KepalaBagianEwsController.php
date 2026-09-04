<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ListKepalaBagianEwsAlertsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ews\KepalaBagianEwsFilterRequest;

class KepalaBagianEwsController extends Controller
{
    public function index(KepalaBagianEwsFilterRequest $request, ListKepalaBagianEwsAlertsAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');
        $validated = $request->validated();

        return view('kabag.ews.index', $action->execute(
            $request->user(),
            $validated['event'] ?? null,
            $validated['status'] ?? null,
            trim((string) ($validated['search'] ?? '')),
            (int) ($validated['per_page'] ?? 10),
        ));
    }
}
