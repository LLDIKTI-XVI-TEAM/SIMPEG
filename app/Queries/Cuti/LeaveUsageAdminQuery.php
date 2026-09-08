<?php

namespace App\Queries\Cuti;

use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveUsageAdminQuery
{
    private const DEFAULT_PER_PAGE = 10;

    private const MAX_LEAVE_TYPE_OPTIONS = 100;

    /** @var array<int, int> */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'effective_date' => 'effective_date',
        'created_at' => 'created_at',
        'usage_year' => 'usage_year',
        'workdays' => 'workdays',
    ];

    /**
     * Memuat histori satu pegawai secara terpagasi dengan relasi dan metadata dokumen yang dibatasi.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, LeaveUsageRecord>
     */
    public function paginate(
        ?string $employeeId,
        array $filters,
        bool $loadWorkspaceRelations = true,
    ): LengthAwarePaginator {
        $relations = [
            // Path penyimpanan tidak ikut read model agar view tidak dapat membocorkan lokasi file privat.
            'documents:id,leave_usage_record_id,original_name,mime_type,size_bytes,created_at',
        ];

        if ($loadWorkspaceRelations) {
            $relations = [
                'employee:id,nama_lengkap,nip',
                'jenisCuti:id,code,nama',
                ...$relations,
                'leaveRequest:id,employee_id,jenis_cuti_id,status,tanggal_mulai,tanggal_selesai,jumlah_hari_kerja',
            ];
        }

        $query = LeaveUsageRecord::query()
            ->select([
                'id',
                'employee_id',
                'leave_type_id',
                'source_type',
                'leave_request_id',
                'leave_request_case_id',
                'usage_year',
                'effective_date',
                'start_date',
                'end_date',
                'workdays',
                'administrative_note',
                'approval_document_number',
                'record_status',
                'replaces_id',
                'correction_reason',
                'recorded_by',
                'created_at',
            ])
            ->with($relations)
            // Agregat terkorlasi menjaga snapshot tetap satu query bersama halaman fakta; constraint
            // parent membatasi maksimal sepuluh tahap sehingga payload per baris tidak tumbuh bebas.
            ->selectSub(
                DB::table('leave_usage_external_approval_steps as approval_step')
                    ->selectRaw(<<<'SQL'
COALESCE(
    jsonb_agg(
        jsonb_build_object(
            'id', approval_step.id,
            'leave_usage_record_id', approval_step.leave_usage_record_id,
            'step_order', approval_step.step_order,
            'step_type', approval_step.step_type,
            'approver_source', approval_step.approver_source,
            'approver_employee_id', approval_step.approver_employee_id,
            'approver_name_snapshot', approval_step.approver_name_snapshot,
            'approver_nip_snapshot', approval_step.approver_nip_snapshot,
            'approver_position_snapshot', approval_step.approver_position_snapshot,
            'approver_institution_snapshot', approval_step.approver_institution_snapshot,
            'acted_on', approval_step.acted_on,
            'result_code', approval_step.result_code,
            'decision_note', approval_step.decision_note
        ) ORDER BY approval_step.step_order
    ),
    '[]'::jsonb
)
SQL)
                    ->whereColumn('approval_step.leave_usage_record_id', 'leave_usage_records.id'),
                'workspace_external_approval_steps',
            )
            // Jalur catat manual tetap merender histori; nama jenis dilipat ke query utama agar tidak perlu eager-load relasi tambahan.
            ->when(
                ! $loadWorkspaceRelations,
                fn (Builder $query) => $query->selectSub(
                    RefJenisCuti::query()
                        ->select('nama')
                        ->whereColumn('ref_jenis_cuti.id', 'leave_usage_records.leave_type_id'),
                    'workspace_usage_type_name',
                ),
            )
            ->when(
                $employeeId !== null,
                fn (Builder $query) => $query->where('employee_id', $employeeId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );

        $this->applyFilters($query, $filters);
        $this->applyOrdering($query, $filters);

        $paginator = $query
            ->paginate($this->perPage($filters), ['*'], 'page_usage')
            ->withQueryString();

        $paginator->getCollection()->each(function (LeaveUsageRecord $record): void {
            $encoded = $record->getAttribute('workspace_external_approval_steps');
            $rows = is_string($encoded) ? json_decode($encoded, true, flags: JSON_THROW_ON_ERROR) : $encoded;
            $steps = collect(is_array($rows) ? $rows : [])
                ->map(function (array $attributes): LeaveUsageExternalApprovalStep {
                    $step = new LeaveUsageExternalApprovalStep;
                    $step->setRawAttributes($attributes, true);
                    $step->exists = true;

                    return $step;
                });

            $record->setRelation('externalApprovalSteps', $steps);
            unset($record->workspace_external_approval_steps);
        });

        return $paginator;
    }

    /**
     * Opsi jenis cuti dibatasi dan menempatkan filter aktif di awal agar pilihan tetap stabil.
     *
     * @return Collection<int, array{id:string,nama:string,code:string}>
     */
    public function leaveTypeOptions(?string $selectedId): Collection
    {
        return DB::table('ref_jenis_cuti')
            ->select(['id', 'nama', 'code'])
            ->when($selectedId, fn ($query, string $id) => $query
                ->orderByRaw('CASE WHEN id = CAST(? AS uuid) THEN 0 ELSE 1 END', [$id]))
            ->orderBy('nama')
            ->orderBy('id')
            ->limit(self::MAX_LEAVE_TYPE_OPTIONS)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'nama' => (string) $row->nama,
                'code' => (string) $row->code,
            ]);
    }

    /** @param Builder<LeaveUsageRecord> $query */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($this->stringFilter($filters, 'source_type'), fn (Builder $query, string $source): Builder => $query
                ->where('source_type', $source))
            ->when($this->stringFilter($filters, 'record_status'), fn (Builder $query, string $status): Builder => $query
                ->where('record_status', $status))
            ->when($this->integerFilter($filters, 'usage_year'), fn (Builder $query, int $year): Builder => $query
                ->where('usage_year', $year))
            ->when($this->stringFilter($filters, 'leave_type'), fn (Builder $query, string $leaveType): Builder => $query
                ->where('leave_type_id', $leaveType));
    }

    /**
     * Tie-breaker tanggal pencatatan dan UUID mencegah baris berpindah antarhalaman saat nilai utama sama.
     *
     * @param  Builder<LeaveUsageRecord>  $query
     */
    private function applyOrdering(Builder $query, array $filters): void
    {
        $requestedSort = $this->stringFilter($filters, 'sort');
        $sort = self::SORT_COLUMNS[$requestedSort ?? ''] ?? 'effective_date';
        $direction = $this->stringFilter($filters, 'direction') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        foreach (['effective_date', 'created_at', 'id'] as $stableColumn) {
            if ($stableColumn !== $sort) {
                $query->orderByDesc($stableColumn);
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function perPage(array $filters): int
    {
        $perPage = $this->integerFilter($filters, 'per_page_usage');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    /** @param array<string, mixed> $filters */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $filters */
    private function integerFilter(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
