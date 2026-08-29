<?php

namespace App\Actions\Dashboards;

use App\Actions\Cuti\PreviewLeaveBalanceAction;
use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\SimpegNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class BuildPegawaiDashboardAction
{
    public function __construct(
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
        private readonly PreviewLeaveBalanceAction $balancePreview,
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
            ? $this->ewsAlerts->preview(5, employeeId: (string) $employeeId)
            : ['alerts' => [], 'total' => 0, 'urgent' => 0, 'warning' => 0, 'info' => 0];

        $employee = null;
        $saldoCuti = null;
        $rule5Active = false;
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

            if ($employee !== null) {
                $saldoCuti = $this->balancePreview->execute($employee, Carbon::now());
                $rule5Active = $saldoCuti['rule_5_active'];
            }

            $cutiAktif = LeaveRequest::query()
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['menunggu_approval', 'ditangguhkan', 'perlu_perubahan', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER])
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
            'dashboardEwsAlerts' => $dashboardEwsData['alerts'],
            'dashboardEwsTotal' => $dashboardEwsData['total'],
            'dashboardEwsUrgent' => $dashboardEwsData['urgent'],
            'dashboardEwsWarning' => $dashboardEwsData['warning'],
            'dashboardEwsInfo' => $dashboardEwsData['info'],
            'dashboardEwsLink' => route('ews.saya'),
            'employee' => $employee,
            'saldoCuti' => $saldoCuti,
            'rule5Active' => $rule5Active,
            'cutiAktif' => $cutiAktif,
            'notifikasi' => $notifikasi,
        ];
    }
}
