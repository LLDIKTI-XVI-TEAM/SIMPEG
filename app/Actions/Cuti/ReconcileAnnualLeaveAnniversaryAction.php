<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Services\Cuti\AnnualLeaveAnniversaryCursorService;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class ReconcileAnnualLeaveAnniversaryAction
{
    private const MAX_CANDIDATES_PER_RUN = 500;

    public function __construct(
        private readonly LeaveBalanceRecalculationService $recalculation,
        private readonly AnnualLeaveAnniversaryCursorService $cursor,
    ) {}

    /**
     * Membentuk atau memulihkan projection hak setelah anniversary melalui replay saldo resmi.
     *
     * @return array{
     *     attempted:int,
     *     processed:int,
     *     failed:int,
     *     failures:list<array{employee_id:string,business_date:string,exception_class:string,correlation_id:string}>
     * }
     */
    public function execute(Carbon $asOf, int $limit): array
    {
        $year = $asOf->year;
        $candidateLimit = max(1, min(self::MAX_CANDIDATES_PER_RUN, $limit));
        $latestEligibleTmt = $this->latestEligibleTmt($asOf);
        $initialCursor = $this->cursor->current();
        $candidates = $this->candidatesAfterCursor(
            $year,
            $latestEligibleTmt,
            $initialCursor,
            $candidateLimit,
        );

        if ($initialCursor !== null && $candidates->count() < $candidateLimit) {
            $candidates = $candidates->concat($this->candidatesThroughCursor(
                $year,
                $latestEligibleTmt,
                $initialCursor,
                $candidateLimit - $candidates->count(),
            ));
        }

        /** @var array{attempted:int,processed:int,failed:int,failures:list<array{employee_id:string,business_date:string,exception_class:string,correlation_id:string}>} $result */
        $result = [
            'attempted' => 0,
            'processed' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($candidates as $employee) {
            $result['attempted']++;

            try {
                // Service rekalkulasi memiliki transaksi per pegawai; exception ditangkap setelah rollback selesai.
                $this->recalculation->recalculateForSystem(
                    $employee,
                    $year,
                    "Rekonsiliasi hak cuti tahunan setelah anniversary {$year}.",
                    'SIMPEG Scheduler',
                );
                $result['processed']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['failures'][] = [
                    'employee_id' => $employee->id,
                    'business_date' => $asOf->toDateString(),
                    'exception_class' => $exception::class,
                    'correlation_id' => (string) Str::uuid(),
                ];
            } finally {
                // Kandidat gagal tetap dilewati agar satu poison record tidak menahan seluruh antrian.
                $this->cursor->advance($employee->id);
            }
        }

        return $result;
    }

    /** @return Builder<Employee> */
    private function candidateQuery(int $year, Carbon $latestEligibleTmt): Builder
    {
        return Employee::query()
            // Filter eligibility sebelum limit mencegah kandidat belum eligible
            // menghabiskan batch dan menahan recovery pegawai di belakangnya.
            ->whereHas('appointments', fn (Builder $query) => $query
                ->whereNotNull('tmt_pengangkatan')
                ->whereDate('tmt_pengangkatan', '<=', $latestEligibleTmt->toDateString()))
            ->where(function (Builder $query) use ($year): void {
                // Projection yang belum pernah terbentuk tetap harus menerima hak saat anniversary tercapai.
                $query->whereDoesntHave('leaveBalances', fn (Builder $balanceQuery) => $balanceQuery
                    ->where('tahun', $year))
                    ->orWhereHas('leaveBalances', fn (Builder $balanceQuery) => $balanceQuery
                        ->where('tahun', $year)
                        ->where('jatah_awal', 0));
            });
    }

    /** @return Collection<int, Employee> */
    private function candidatesAfterCursor(
        int $year,
        Carbon $latestEligibleTmt,
        ?string $cursor,
        int $limit,
    ): Collection {
        return $this->candidateQuery($year, $latestEligibleTmt)
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Employee> */
    private function candidatesThroughCursor(
        int $year,
        Carbon $latestEligibleTmt,
        string $cursor,
        int $limit,
    ): Collection {
        return $this->candidateQuery($year, $latestEligibleTmt)
            ->where('id', '<=', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Menghasilkan batas TMT yang setara dengan anniversary no-overflow policy.
     */
    private function latestEligibleTmt(Carbon $asOf): Carbon
    {
        $cutoff = $asOf->copy()->startOfDay()->subYearNoOverflow();

        if ($asOf->month === 2 && $asOf->day === 28 && $cutoff->isLeapYear()) {
            return $cutoff->addDay();
        }

        return $cutoff;
    }
}
