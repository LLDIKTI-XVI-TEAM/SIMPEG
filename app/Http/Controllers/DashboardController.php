<?php

namespace App\Http\Controllers;

use App\Actions\Dashboards\BuildPegawaiDashboardAction;
use App\Actions\Ews\ListActiveEwsAlertsAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        ListActiveEwsAlertsAction $ewsAlerts,
        BuildPegawaiDashboardAction $pegawaiDashboard,
    ): View|RedirectResponse {
        $user = $request->user();
        $role = $user?->role;

        if ($role === 'pimpinan') {
            return redirect()->route('pimpinan.dashboard');
        }

        if ($role === 'kepala_bagian') {
            return redirect()->route('kepala-bagian.dashboard');
        }

        if ($user !== null && $role === 'pegawai') {
            return view('pegawai.dashboard', $pegawaiDashboard->execute($user));
        }

        // Blok admin masih inline sampai BuildAdminDashboardAction tersedia;
        // dashboard admin memuat EWS lintas pegawai tanpa filter employee.
        $dashboardEwsData = $ewsAlerts->execute(null, null, null);
        $dashboardEwsAlerts = $dashboardEwsData['alerts'];

        return view('admin.dashboard', [
            'dashboardEwsAlerts' => array_slice($dashboardEwsAlerts, 0, 5),
            'dashboardEwsTotal' => count($dashboardEwsAlerts),
            'dashboardEwsUrgent' => collect($dashboardEwsAlerts)->where('urgency', 'danger')->count(),
            'dashboardEwsWarning' => collect($dashboardEwsAlerts)->where('urgency', 'warning')->count(),
            'dashboardEwsInfo' => collect($dashboardEwsAlerts)->where('urgency', 'success')->count(),
            'dashboardEwsLink' => in_array($role, ['super_admin', 'admin_kepegawaian'], true)
                ? route('ews')
                : '#ews-section',
        ]);
    }
}
