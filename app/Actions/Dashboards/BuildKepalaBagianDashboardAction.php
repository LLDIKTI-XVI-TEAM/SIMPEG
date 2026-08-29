<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;

class BuildKepalaBagianDashboardAction
{
    public function __construct(
        private readonly KepalaBagianScopeService $scope,
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $today = now()->toDateString();
        $reportIds = $this->scope->directReportIds($user);
        $activeReports = $this->scope->directReports($user);

        $reports = (clone $activeReports)
            ->with([
                'jenisPegawai:id,nama',
                'positionHistories' => fn ($query) => $query
                    ->with('unitKerja:id,nama')
                    ->where('is_latest', true)
                    ->orderByDesc('tmt_jabatan')
                    ->limit(1),
            ])
            ->withExists([
                'leaveRequests as sedang_cuti' => fn ($query) => $query
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $today)
                    ->whereDate('tanggal_selesai', '>=', $today),
            ])
            ->orderBy('nama_lengkap')
            ->limit(5)
            ->get();

        $pendingLeaves = LeaveRequest::query()
            ->with(['employee:id,nama_lengkap,nip', 'jenisCuti:id,nama'])
            ->whereIn('employee_id', $reportIds)
            ->whereIn('status', ['menunggu_approval', 'ditangguhkan'])
            ->whereHas('steps', fn ($steps) => $steps
                ->where('status', 'active')
                ->where('approver_employee_id', $user->employee_id))
            ->orderBy('tanggal_mulai')
            ->limit(5)
            ->get();

        $ews = $this->ewsAlerts->preview(5, employeeIds: $reportIds)['alerts'];

        return [
            'namaKepalaBagian' => $user->name,
            'totalBawahanAktif' => (clone $activeReports)->count(),
            'cutiPending' => $pendingLeaves->count(),
            'bawahanSedangCuti' => (clone $activeReports)
                ->whereHas('leaveRequests', fn ($leaves) => $leaves
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $today)
                    ->whereDate('tanggal_selesai', '>=', $today))
                ->count(),
            'bawahan' => $reports,
            'pendingLeaves' => $pendingLeaves,
            'ewsBawahan' => $ews,
        ];
    }
}
