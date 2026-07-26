<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\SimpegNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class BuildPegawaiDashboardAction
{
    public function __construct(
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
    ) {}

    /**
     * Menyusun seluruh data dashboard pegawai: profil ringkas, saldo cuti,
     * pengajuan cuti aktif, lima notifikasi terbaru, dan blok EWS pribadi.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $employeeId = $user->employee_id;

        // Pegawai yang belum terpetakan ke data employee (mapping SSO belum
        // lengkap) tetap mendapat dashboard kosong yang aman tanpa query null.
        $dashboardEwsData = $employeeId !== null
            ? $this->ewsAlerts->execute(null, null, (string) $employeeId)
            : ['alerts' => []];
        $dashboardEwsAlerts = $dashboardEwsData['alerts'];

        $employee = null;
        $saldoCuti = null;
        $cutiAktif = new Collection;
        $notifikasi = new Collection;

        if ($employeeId !== null) {
            $employee = Employee::query()
                ->with([
                    'jenisPegawai',
                    'statusPegawai',
                    // Posisi terbaru + unit kerja dimuat sekali di sini agar Blade
                    // tidak memicu query tambahan saat merender nama unit kerja.
                    'positionHistories' => fn ($query) => $query
                        ->with('unitKerja:id,nama')
                        ->where('is_latest', true)
                        ->orderByDesc('tmt_jabatan')
                        ->limit(1),
                ])
                ->find($employeeId);

            $saldoCuti = LeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('tahun', now()->year)
                ->first();

            $cutiAktif = LeaveRequest::query()
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['menunggu_approval', 'ditangguhkan', 'perlu_perubahan'])
                ->latest()
                ->take(5)
                ->get();

            // Kolom user_id pada tabel notifications adalah FK ke employees
            // (bukan users), sehingga filternya wajib memakai employee_id.
            $notifikasi = SimpegNotification::query()
                ->where('user_id', $employeeId)
                ->latest()
                ->take(5)
                ->get();
        }

        return [
            'dashboardEwsAlerts' => array_slice($dashboardEwsAlerts, 0, 5),
            'dashboardEwsTotal' => count($dashboardEwsAlerts),
            'dashboardEwsUrgent' => collect($dashboardEwsAlerts)->where('urgency', 'danger')->count(),
            'dashboardEwsWarning' => collect($dashboardEwsAlerts)->where('urgency', 'warning')->count(),
            'dashboardEwsInfo' => collect($dashboardEwsAlerts)->where('urgency', 'success')->count(),
            'dashboardEwsLink' => route('ews.saya'),
            'employee' => $employee,
            'saldoCuti' => $saldoCuti,
            'cutiAktif' => $cutiAktif,
            'notifikasi' => $notifikasi,
        ];
    }
}
