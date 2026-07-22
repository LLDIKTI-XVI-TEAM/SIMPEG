<?php

namespace App\Http\Controllers;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\SimpegNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, ListActiveEwsAlertsAction $ewsAlerts): View|RedirectResponse
    {
        $user = $request->user();
        $role = $user?->role;

        if ($role === 'pimpinan') {
            return redirect()->route('pimpinan.dashboard');
        }

        if ($role === 'kepala_bagian') {
            return redirect()->route('kepala-bagian.dashboard');
        }

        $isPegawai = $role === 'pegawai';
        $employeeId = $isPegawai ? $user?->employee_id : null;

        $dashboardEwsData = $employeeId || ! $isPegawai
            ? $ewsAlerts->execute(null, null, $employeeId ? (string) $employeeId : null)
            : ['alerts' => []];

        $dashboardEwsAlerts = $dashboardEwsData['alerts'];

        $viewData = [
            'dashboardEwsAlerts' => array_slice($dashboardEwsAlerts, 0, 5),
            'dashboardEwsTotal' => count($dashboardEwsAlerts),
            'dashboardEwsUrgent' => collect($dashboardEwsAlerts)->where('urgency', 'danger')->count(),
            'dashboardEwsWarning' => collect($dashboardEwsAlerts)->where('urgency', 'warning')->count(),
            'dashboardEwsInfo' => collect($dashboardEwsAlerts)->where('urgency', 'success')->count(),
            'dashboardEwsLink' => $isPegawai
                ? route('ews.saya')
                : (in_array($role, ['super_admin', 'admin_kepegawaian'], true) ? route('ews') : '#ews-section'),
        ];

        if ($isPegawai) {
            $employee = Employee::with(['jenisPegawai', 'statusPegawai'])
                ->where('id', $employeeId)
                ->first();

            $saldoCuti = LeaveBalance::where('employee_id', $employeeId)
                ->where('tahun', date('Y'))
                ->first();

            $cutiAktif = LeaveRequest::where('employee_id', $employeeId)
                ->whereIn('status', ['menunggu_approval', 'ditangguhkan', 'perlu_perubahan'])
                ->latest()
                ->take(5)
                ->get();

            $notifikasi = SimpegNotification::where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get();

            $viewData = array_merge($viewData, [
                'employee' => $employee,
                'saldoCuti' => $saldoCuti,
                'cutiAktif' => $cutiAktif,
                'notifikasi' => $notifikasi,
            ]);

            return view('pegawai.dashboard', $viewData);
        }

        return view('admin.dashboard', $viewData);
    }
}
