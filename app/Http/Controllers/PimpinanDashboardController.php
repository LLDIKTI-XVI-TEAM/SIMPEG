<?php

namespace App\Http\Controllers;

use App\Actions\Dashboards\BuildPimpinanDashboardAction;
use Illuminate\Http\Request;

class PimpinanDashboardController extends Controller
{
    public function index(Request $request, BuildPimpinanDashboardAction $action)
    {
        return view('pimpinan.dashboard', $action->execute($request->user()));
    }
}
