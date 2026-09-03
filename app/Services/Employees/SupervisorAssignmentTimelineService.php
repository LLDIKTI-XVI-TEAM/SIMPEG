<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\SupervisorAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SupervisorAssignmentTimelineService
{
    /**
     * Membentuk satu rencana yang menjadi sumber mutasi dan hasil Kepala Bagian hari ini.
     *
     * @param  Collection<int, SupervisorAssignment>  $assignments
     * @return array{
     *     is_no_op:bool,
     *     projected_today_supervisor_id:string|null,
     *     delete_assignment_id:string|null,
     *     updates:array<string, array{kepala_bagian_id?:string, tanggal_berakhir?:string|null}>,
     *     create:array{kepala_bagian_id:string, tanggal_mulai:string, tanggal_berakhir:string|null}|null
     * }
     */
    public function plan(
        Collection $assignments,
        ?string $kepalaBagianId,
        Carbon $effective,
        Carbon $today,
    ): array {
        $exact = $assignments->first(
            fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->isSameDay($effective),
        );
        $isNoOp = $exact !== null && $exact->kepala_bagian_id === $kepalaBagianId;
        $intervals = $assignments
            ->map(fn (SupervisorAssignment $assignment): array => [
                'id' => $assignment->id,
                'kepala_bagian_id' => $assignment->kepala_bagian_id,
                'tanggal_mulai' => $assignment->tanggal_mulai->copy(),
                'tanggal_berakhir' => $assignment->tanggal_berakhir?->copy(),
            ])
            ->values()
            ->all();
        $updates = [];
        $deleteAssignmentId = null;
        $create = null;

        if (! $isNoOp && $exact !== null) {
            if ($kepalaBagianId === null) {
                $deleteAssignmentId = $exact->id;
                $intervals = array_values(array_filter(
                    $intervals,
                    fn (array $interval): bool => $interval['id'] !== $exact->id,
                ));
            } else {
                $next = $assignments->first(
                    fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->gt($effective),
                );
                $tanggalBerakhir = $next?->tanggal_mulai->copy()->subDay();
                $updates[$exact->id] = [
                    'kepala_bagian_id' => $kepalaBagianId,
                    'tanggal_berakhir' => $tanggalBerakhir?->toDateString(),
                ];
                $this->replaceInterval(
                    $intervals,
                    $exact->id,
                    $kepalaBagianId,
                    $tanggalBerakhir,
                );

                $previous = $assignments->last(
                    fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->lt($effective),
                );

                if ($previous !== null
                    && ($previous->tanggal_berakhir === null || $previous->tanggal_berakhir->gte($effective))) {
                    $previousEnd = $effective->copy()->subDay();
                    $updates[$previous->id]['tanggal_berakhir'] = $previousEnd->toDateString();
                    $this->replaceIntervalEnd($intervals, $previous->id, $previousEnd);
                }
            }
        } elseif (! $isNoOp) {
            $containing = $assignments->first(function (SupervisorAssignment $assignment) use ($effective): bool {
                return $assignment->tanggal_mulai->lte($effective)
                    && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte($effective));
            });
            $next = $assignments->first(
                fn (SupervisorAssignment $assignment): bool => $assignment->tanggal_mulai->gt($effective),
            );

            if ($containing !== null) {
                $containingEnd = $effective->copy()->subDay();
                $updates[$containing->id]['tanggal_berakhir'] = $containingEnd->toDateString();
                $this->replaceIntervalEnd($intervals, $containing->id, $containingEnd);
            }

            if ($kepalaBagianId !== null) {
                $tanggalBerakhir = $next?->tanggal_mulai->copy()->subDay();
                $create = [
                    'kepala_bagian_id' => $kepalaBagianId,
                    'tanggal_mulai' => $effective->toDateString(),
                    'tanggal_berakhir' => $tanggalBerakhir?->toDateString(),
                ];
                $intervals[] = [
                    'id' => '',
                    'kepala_bagian_id' => $kepalaBagianId,
                    'tanggal_mulai' => $effective->copy(),
                    'tanggal_berakhir' => $tanggalBerakhir,
                ];
            }
        }

        return [
            'is_no_op' => $isNoOp,
            'projected_today_supervisor_id' => $this->supervisorAt($intervals, $today),
            'delete_assignment_id' => $deleteAssignmentId,
            'updates' => $updates,
            'create' => $create,
        ];
    }

    /**
     * Menjalankan persis operasi yang sudah dihitung plan tanpa menghitung ulang transisi tanggal.
     *
     * @param  array{
     *     delete_assignment_id:string|null,
     *     updates:array<string, array{kepala_bagian_id?:string, tanggal_berakhir?:string|null}>,
     *     create:array{kepala_bagian_id:string, tanggal_mulai:string, tanggal_berakhir:string|null}|null
     * }  $plan
     */
    public function persist(Employee $employee, array $plan): void
    {
        $updates = $plan['updates'];
        ksort($updates, SORT_STRING);

        foreach ($updates as $assignmentId => $values) {
            SupervisorAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereKey($assignmentId)
                ->firstOrFail()
                ->update($values);
        }

        if ($plan['delete_assignment_id'] !== null) {
            SupervisorAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereKey($plan['delete_assignment_id'])
                ->firstOrFail()
                ->delete();
        }

        if ($plan['create'] !== null) {
            SupervisorAssignment::create([
                'employee_id' => $employee->id,
                ...$plan['create'],
            ]);
        }
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
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function assertNoOverlap(Employee $employee): void
    {
        $assignments = $employee->supervisorAssignments()
            ->orderBy('tanggal_mulai')
            ->orderBy('id')
            ->get();

        for ($index = 1; $index < $assignments->count(); $index++) {
            $previous = $assignments[$index - 1];
            $current = $assignments[$index];

            if ($previous->tanggal_berakhir === null
                || $previous->tanggal_berakhir->gte($current->tanggal_mulai)) {
                throw ValidationException::withMessages([
                    'effective_date' => 'Rentang penugasan Atasan Langsung tidak boleh tumpang tindih.',
                ]);
            }
        }
    }

    /** @param list<array{id:string, kepala_bagian_id:string, tanggal_mulai:Carbon, tanggal_berakhir:Carbon|null}> $intervals */
    private function replaceInterval(
        array &$intervals,
        string $assignmentId,
        string $kepalaBagianId,
        ?Carbon $tanggalBerakhir,
    ): void {
        foreach ($intervals as &$interval) {
            if ($interval['id'] === $assignmentId) {
                $interval['kepala_bagian_id'] = $kepalaBagianId;
                $interval['tanggal_berakhir'] = $tanggalBerakhir;

                break;
            }
        }
        unset($interval);
    }

    /** @param list<array{id:string, kepala_bagian_id:string, tanggal_mulai:Carbon, tanggal_berakhir:Carbon|null}> $intervals */
    private function replaceIntervalEnd(array &$intervals, string $assignmentId, Carbon $tanggalBerakhir): void
    {
        foreach ($intervals as &$interval) {
            if ($interval['id'] === $assignmentId) {
                $interval['tanggal_berakhir'] = $tanggalBerakhir;

                break;
            }
        }
        unset($interval);
    }

    /**
     * @param  list<array{id:string, kepala_bagian_id:string, tanggal_mulai:Carbon, tanggal_berakhir:Carbon|null}>  $intervals
     */
    private function supervisorAt(array $intervals, Carbon $date): ?string
    {
        usort($intervals, fn (array $left, array $right): int => [
            $left['tanggal_mulai']->getTimestamp(),
            $left['id'],
        ] <=> [
            $right['tanggal_mulai']->getTimestamp(),
            $right['id'],
        ]);

        foreach (array_reverse($intervals) as $interval) {
            if ($interval['tanggal_mulai']->lte($date)
                && ($interval['tanggal_berakhir'] === null || $interval['tanggal_berakhir']->gte($date))) {
                return $interval['kepala_bagian_id'];
            }
        }

        return null;
    }
}
