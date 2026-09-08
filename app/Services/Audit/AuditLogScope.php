<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Satu scope untuk daftar, detail, dan filter; aktor perubahan bukan pemilik data audit. */
final class AuditLogScope
{
    /** Target yang relasi pegawainya tersimpan pada row sumber, bukan ditebak dari JSON audit. */
    private const EMPLOYEE_TARGETS = [
        'LeaveRequest' => 'leave_requests',
        'LeaveRequestCase' => 'leave_request_cases',
        'LeaveUsageRecord' => 'leave_usage_records',
        'LeaveBalance' => 'leave_balances',
        'LeaveBalanceReservationEvent' => 'leave_balance_reservation_events',
        'LeaveApprovalChain' => 'leave_approval_chains',
        'KepalaLembagaSupportingDocument' => 'kepala_lembaga_supporting_documents',
        'EmployeeFamily' => 'employee_families',
        'RankHistory' => 'rank_histories',
        'PositionHistory' => 'position_histories',
        'SalaryHistory' => 'salary_histories',
        'DisciplineRecord' => 'discipline_records',
        'EducationHistory' => 'education_histories',
        'Document' => 'documents',
        'EwsAlert' => 'ews_alerts',
    ];

    public function __construct(private readonly EmployeeDashboardScopeService $employees) {}

    /**
     * Target hilang/tidak dikenal dan log sistem tidak dibuka kepada scope non-global.
     * Row sumber soft-deleted tetap dapat memetakan histori; scope Employee aktual tetap wajib.
     *
     * @return Builder<AuditLog>
     */
    public function for(?User $actor): Builder
    {
        $query = AuditLog::query();
        if ($actor === null || ! $actor->hasPermission('audit_logs.read')) {
            return $query->whereRaw('1 = 0');
        }
        if (in_array($actor->getEffectiveRole(), ['super_admin', 'admin_kepegawaian', 'pimpinan'], true)) {
            return $query;
        }

        $employeeIds = $this->employees->for($actor)->select('employees.id');

        return $query->where(function (Builder $targets) use ($employeeIds): void {
            $targets->where(function (Builder $employee) use ($employeeIds): void {
                $employee->where('auditable_type', 'Employee')->whereIn('auditable_id', $employeeIds);
            });
            foreach (self::EMPLOYEE_TARGETS as $type => $table) {
                $targets->orWhere(function (Builder $target) use ($type, $table, $employeeIds): void {
                    $target->where('auditable_type', $type)->whereIn('auditable_id',
                        DB::table($table)->select('id')->whereIn('employee_id', $employeeIds));
                });
            }
            foreach (['LeaveCancellationRequest' => 'leave_cancellation_requests', 'LeaveProof' => 'leave_proofs'] as $type => $table) {
                $targets->orWhere(function (Builder $target) use ($type, $table, $employeeIds): void {
                    $target->where('auditable_type', $type)->whereIn('auditable_id',
                        DB::table($table)->select($table.'.id')
                            ->join('leave_requests', 'leave_requests.id', '=', $table.'.leave_request_id')
                            ->whereIn('leave_requests.employee_id', $employeeIds));
                });
            }
        });
    }
}
