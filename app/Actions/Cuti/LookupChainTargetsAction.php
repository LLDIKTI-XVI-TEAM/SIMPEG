<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;

class LookupChainTargetsAction
{
    public function __construct(private readonly EmployeeDashboardScopeService $scope) {}

    /** Target selalu scoped dan aktif; exact unit diturunkan dari riwayat terkini tanpa memasukkan subunit. */
    public function execute(User $actor, array $filters): array
    {
        abort_unless($actor->hasPermission('cuti.configure'), 403);
        $query = $this->scope->forIdentity($actor)->whereActiveStatus()
            ->select(['employees.id', 'nama_lengkap', 'nip'])
            ->withExists(['leaveApprovalChains as has_active_chain' => fn ($chain) => $chain->where('is_active', true)]);
        if (($filters['unit_kerja_id'] ?? null) !== null) {
            $query->whereHas('positionHistories', fn ($position) => $position->where('is_latest', true)->where('unit_kerja_id', $filters['unit_kerja_id']));
        }
        if (($filters['q'] ?? '') !== '' && ($filters['q'] ?? null) !== null) {
            $keyword = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn ($employee) => $employee->whereRaw('lower(nama_lengkap) like ?', [$keyword])->orWhereRaw('lower(nip) like ?', [$keyword]));
        }
        $page = $query->orderBy('nama_lengkap')->orderBy('employees.id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'data' => $page->getCollection()->map(fn (Employee $employee) => [
                'id' => $employee->id, 'nama_lengkap' => $employee->nama_lengkap, 'nip' => $employee->nip,
                'has_active_chain' => (bool) $employee->getAttribute('has_active_chain'),
            ])->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ];
    }
}
