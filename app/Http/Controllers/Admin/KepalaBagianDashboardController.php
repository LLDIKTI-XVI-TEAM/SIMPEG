<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Dashboards\BuildKepalaBagianDashboardAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KepalaBagianDashboardController extends Controller
{
    public function index(Request $request, BuildKepalaBagianDashboardAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');

        return view('kabag.dashboard', $action->execute($request->user()));
    }
}
