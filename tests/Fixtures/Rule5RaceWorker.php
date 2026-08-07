<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\LeaveApprovalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
while (! is_file($input['barrier'])) {
    usleep(10_000);
}

try {
    if ($input['mode'] === 'approve_large') {
        $request = LeaveRequest::query()->findOrFail($input['request_id']);
        $approver = Employee::query()->findOrFail($input['approver_id']);
        app(LeaveApprovalService::class)->approve($request, $approver);
    } else {
        DB::transaction(function () use ($input): void {
            // Lock employee FIRST before creating LeaveRequest to ensure proper serialization
            // with concurrent Cuti Besar approval. This ensures Rule 5 checks see a consistent
            // state of either: (1) approved Cuti Besar + no annual leave, or (2) active annual
            // leave + no approved Cuti Besar. Without this lock order, both could succeed.
            $employee = Employee::query()->whereKey($input['employee_id'])->lockForUpdate()->firstOrFail();
            
            $annual = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();
            
            // Check for approved Cuti Besar while holding the employee lock
            $service = app(\App\Services\Cuti\LeaveBalanceService::class);
            $service->assertAnnualLeaveAllowed($employee, 2026);
            
            $request = LeaveRequest::create([
                'employee_id' => $input['employee_id'],
                'jenis_cuti_id' => $annual->id,
                'tanggal_mulai' => '2026-09-01',
                'tanggal_selesai' => '2026-09-03',
                'jumlah_hari_kerja' => 3,
                'alasan' => 'Race Rule 5.',
                'status' => 'menunggu_approval',
            ]);
            app(LeaveBalanceReservationService::class)->reserveForNewRequest($request);
        });
    }

    file_put_contents($input['result'], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($input['result'], json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
