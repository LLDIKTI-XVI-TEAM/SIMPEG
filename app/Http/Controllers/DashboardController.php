<?php

namespace App\Http\Controllers;

use App\Actions\Dashboards\BuildAdminDashboardAction;
use App\Actions\Dashboards\BuildPegawaiDashboardAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildAdminDashboardAction $adminDashboard,
        BuildPegawaiDashboardAction $pegawaiDashboard,
    ): View|RedirectResponse {
        $user = $request->user();
        $role = $user?->getEffectiveRole();

        if ($role === 'pimpinan') {
            return redirect()->route('pimpinan.dashboard');
        }

        if ($role === 'kepala_bagian') {
            return redirect()->route('kepala-bagian.dashboard');
        }

        if ($user !== null && $role === 'pegawai') {
            return view('pegawai.dashboard', $pegawaiDashboard->execute($user));
        }

        return view('admin.dashboard', $adminDashboard->execute($user));
    }
}
