<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\SupervisorAssignment;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengatur riwayat Kepala Bagian efektif tanpa membentuk rentang tanggal yang tumpang tindih.
 */
class AssignSupervisorAction
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Menyimpan penugasan pada tanggal efektif dan menyelaraskan pointer serta chain yang berlaku hari ini.
     *
     * @throws ValidationException
     */
    public function execute(
        Employee $employee,
        ?string $kepalaBagianId,
        string $effectiveDate,
        ?Request $request = null,
    ): Employee {
        if ($kepalaBagianId === $employee->id) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => 'Pegawai tidak bisa menjadi kepala bagian untuk diri sendiri.',
            ]);
        }

        if ($kepalaBagianId !== null && ! Employee::whereKey($kepalaBagianId)->exists()) {
            throw ValidationException::withMessages([
                'kepala_bagian_id' => 'Kepala bagian yang dipilih tidak ditemukan.',
            ]);
        }

        $effective = Carbon::createFromFormat('Y-m-d', $effectiveDate)->startOfDay();
        $today = today();

        DB::transaction(function () use ($employee, $kepalaBagianId, $effective, $today, $request): void {
            /** @var Collection<int, SupervisorAssignment> $assignments */
            $assignments = SupervisorAssignment::query()
                ->where('employee_id', $employee->id)
                ->orderBy('tanggal_mulai')
                ->lockForUpdate()
                ->get();

            $exact = $assignments->first(
                fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->isSameDay($effective),
            );

            // Penetapan identik pada tanggal yang sama adalah no-op agar histori dan audit tidak berlipat.
            if ($exact !== null && $exact->kepala_bagian_id === $kepalaBagianId) {
                return;
            }

            $oldPointer = $employee->kepala_bagian_id;

            if ($exact !== null) {
                if ($kepalaBagianId === null) {
                    $exact->delete();
                } else {
                    $exact->update(['kepala_bagian_id' => $kepalaBagianId]);

                    $previous = $assignments->last(
                        fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->lt($effective),
                    );
                    $next = $assignments->first(
                        fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->gt($effective),
                    );

                    // Data legacy dapat memiliki lebih dari satu baris terbuka; batasi tetangga tanpa mengubah urutan histori.
                    if ($previous !== null
                        && ($previous->tanggal_berakhir === null || $previous->tanggal_berakhir->gte($effective))) {
                        $previous->update(['tanggal_berakhir' => $effective->copy()->subDay()->toDateString()]);
                    }

                    $exact->update([
                        'tanggal_berakhir' => $next?->tanggal_mulai->copy()->subDay()->toDateString(),
                    ]);
                }
            } else {
                $containing = $assignments->first(function (SupervisorAssignment $assignment) use ($effective): bool {
                    return $assignment->tanggal_mulai->lte($effective)
                        && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte($effective));
                });
                $next = $assignments->first(
                    fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->gt($effective),
                );

                // Karena batas inklusif, interval lama dan baru dipisahkan tepat pada H-1.
                if ($containing !== null) {
                    $containing->update(['tanggal_berakhir' => $effective->copy()->subDay()->toDateString()]);
                }

                if ($kepalaBagianId !== null) {
                    SupervisorAssignment::create([
                        'employee_id' => $employee->id,
                        'kepala_bagian_id' => $kepalaBagianId,
                        'tanggal_mulai' => $effective->toDateString(),
                        'tanggal_berakhir' => $next?->tanggal_mulai->copy()->subDay()->toDateString(),
                    ]);
                }
            }

            $this->assertNoOverlap($employee);

            $todayAssignment = $this->assignmentAt($employee, $today, lock: true);
            $todaySupervisorId = $todayAssignment?->kepala_bagian_id;
            $employee->update(['kepala_bagian_id' => $todaySupervisorId]);

            // Chain tersimpan mengikuti Kepala Bagian hari ini; penugasan masa depan baru dipakai saat efektif.
            if ($effective->lte($today) && $todaySupervisorId !== null) {
                $this->syncActiveChain($employee, $todaySupervisorId);
            }

            // Audit berada dalam transaksi agar kegagalannya membatalkan histori, pointer, dan chain sekaligus.
            $this->audit->logOrFail(
                'UPDATE',
                'Employee',
                $employee->id,
                ['kepala_bagian_id' => $oldPointer],
                [
                    'kepala_bagian_id' => $todaySupervisorId,
                    'assigned_kepala_bagian_id' => $kepalaBagianId,
                    'effective_date' => $effective->toDateString(),
                ],
                $request,
            );
        });

        return $employee->refresh()->load('kepalaBagian');
    }

    /**
     * Mengambil penugasan dengan predikat tanggal bisnis inklusif.
     */
    public function assignmentAt(Employee $employee, Carbon $date, bool $lock = false): ?SupervisorAssignment
    {
        $query = $employee->supervisorAssignments()
            ->whereDate('tanggal_mulai', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('tanggal_berakhir')
                    ->orWhereDate('tanggal_berakhir', '>=', $date->toDateString());
            })
            ->orderByDesc('tanggal_mulai');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function syncActiveChain(Employee $employee, string $kepalaBagianId): void
    {
        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        $chain?->steps()
            ->where('step_type', 'kepala_bagian')
            ->orderBy('step_order')
            ->first()
            ?->update(['approver_employee_id' => $kepalaBagianId]);
    }

    private function assertNoOverlap(Employee $employee): void
    {
        $assignments = $employee->supervisorAssignments()->orderBy('tanggal_mulai')->get();

        for ($index = 1; $index < $assignments->count(); $index++) {
            $previous = $assignments[$index - 1];
            $current = $assignments[$index];

            if ($previous->tanggal_berakhir === null
                || $previous->tanggal_berakhir->gte($current->tanggal_mulai)) {
                throw ValidationException::withMessages([
                    'effective_date' => 'Rentang penugasan Kepala Bagian tidak boleh tumpang tindih.',
                ]);
            }
        }
    }
}
