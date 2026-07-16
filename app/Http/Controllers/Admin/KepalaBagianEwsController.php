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

        return view('kabag.ews.index', $action->execute(
            $request->user(),
            $request->validated('event'),
            $request->validated('status'),
        ));
    }
}
