<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use Illuminate\Support\Collection;

/**
 * Menyiapkan data terbatas untuk halaman konfigurasi chain cuti.
 * Pencarian pegawai dan kandidat approver dibatasi agar halaman admin tidak memuat seluruh data pegawai ke browser.
 */
class ShowCutiConfigPageAction
{
    private const TARGET_EMPLOYEE_LIMIT = 50;

    private const APPROVER_LIMIT = 50;

    /**
     * Menampilkan kandidat pegawai sesuai pencarian serta detail chain pegawai yang dipilih.
     * Kepala Bagian dihitung di server dari relasi yang sama dengan validasi request agar UI bukan sumber kebenaran.
     *
     * @return array<string, mixed>
     */
    public function execute(?string $search, ?string $selectedEmployeeId, ?string $approverSearch): array
    {
        $targetEmployees = $this->targetEmployees($search);
        $selectedEmployee = $this->selectedEmployee($selectedEmployeeId);
        $activeChain = $this->activeChain($selectedEmployee);
        $globalPybmc = LeavePybmcGlobalConfig::query()
            ->with('approver:id,nama_lengkap,nip')
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();
        $selectedKepalaBagian = $selectedEmployee?->kepalaBagian
            ?? $selectedEmployee?->supervisorAssignments->first()?->kepalaBagian;

        return [
            'search' => $search,
            'targetEmployees' => $targetEmployees,
            'selectedEmployee' => $selectedEmployee,
            'selectedKepalaBagian' => $selectedKepalaBagian,
            'approverSearch' => $approverSearch,
            'approverCandidates' => $this->approverCandidates($approverSearch, $activeChain, $globalPybmc),
            'initialVerifierSteps' => $this->initialVerifierSteps($activeChain),
            'initialPybmcEmployeeId' => $this->initialPybmcEmployeeId($activeChain),
            'auditRows' => $this->auditRows(),
            'chainStats' => [
                'active' => LeaveApprovalChain::query()->where('is_active', true)->count(),
            ],
            'globalPybmc' => $globalPybmc,
        ];
    }

    /** @return Collection<int, Employee> */
    private function targetEmployees(?string $search): Collection
    {
        if ($search === null || trim($search) === '') {
            return collect();
        }

        $keyword = '%'.mb_strtolower(trim($search)).'%';

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir', 'kepala_bagian_id'])
            ->where('status_aktif', 'Aktif')
            ->where(function ($query) use ($keyword): void {
                $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('lower(nip) like ?', [$keyword]);
            })
            ->orderBy('nama_lengkap')
            ->limit(self::TARGET_EMPLOYEE_LIMIT)
            ->get();
    }

    private function selectedEmployee(?string $selectedEmployeeId): ?Employee
    {
        if ($selectedEmployeeId === null || $selectedEmployeeId === '') {
            return null;
        }

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir', 'kepala_bagian_id'])
            ->with([
                'kepalaBagian:id,nama_lengkap,nip',
                'supervisorAssignments' => fn ($query) => $query
                    ->whereNull('tanggal_berakhir')
                    ->with('kepalaBagian:id,nama_lengkap,nip'),
            ])
            ->where('status_aktif', 'Aktif')
            ->find($selectedEmployeeId);
    }

    /** @return Collection<int, Employee> */
    private function approverCandidates(?string $search, ?LeaveApprovalChain $activeChain, ?LeavePybmcGlobalConfig $globalPybmc): Collection
    {
        $preservedIds = $activeChain?->steps
            ->pluck('approver_employee_id')
            ->push($globalPybmc?->approver_employee_id)
            ->filter()
            ->unique()
            ->values() ?? collect();

        $activeCandidates = collect();

        if ($search !== null && trim($search) !== '') {
            $keyword = '%'.mb_strtolower(trim($search)).'%';
            $activeCandidates = Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->where('status_aktif', 'Aktif')
                ->where(function ($query) use ($keyword): void {
                    $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                        ->orWhereRaw('lower(nip) like ?', [$keyword]);
                })
                ->orderBy('nama_lengkap')
                ->limit(self::APPROVER_LIMIT)
                ->get();
        }

        $preservedCandidates = $preservedIds->isEmpty()
            ? collect()
            : Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->whereIn('id', $preservedIds)
                ->get();

        return $preservedCandidates
            ->merge($activeCandidates)
            ->unique('id')
            ->sortBy('nama_lengkap')
            ->values();
    }

    private function activeChain(?Employee $selectedEmployee): ?LeaveApprovalChain
    {
        if ($selectedEmployee === null) {
            return null;
        }

        return LeaveApprovalChain::query()
            ->select(['id', 'employee_id'])
            ->with('steps:id,leave_approval_chain_id,step_order,step_type,role_label,approver_employee_id,is_final')
            ->where('employee_id', $selectedEmployee->id)
            ->where('is_active', true)
            ->first();
    }

    /** @return list<array{role_label:string, approver_employee_id:string}> */
    private function initialVerifierSteps(?LeaveApprovalChain $activeChain): array
    {
        return $activeChain?->steps
            ->where('step_type', 'verifier')
            ->sortBy('step_order')
            ->map(fn ($step): array => [
                'role_label' => $step->role_label,
                'approver_employee_id' => $step->approver_employee_id,
            ])
            ->values()
            ->all() ?? [];
    }

    private function initialPybmcEmployeeId(?LeaveApprovalChain $activeChain): ?string
    {
        return $activeChain?->steps
            ->firstWhere('is_final', true)
            ?->approver_employee_id;
    }

    /**
     * @return list<array{
     *     id:string,
     *     created_at:string,
     *     user_name:string,
     *     source:string,
     *     event:string,
     *     reason:string,
     *     ip_address:string|null,
     *     user_agent:string|null
     * }>
     */
    private function auditRows(): array
    {
        return AuditLog::query()
            ->select(['id', 'user_name', 'event', 'auditable_type', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'])
            ->whereIn('auditable_type', ['ApprovalConfig', 'LeaveApprovalChain', 'LeavePybmcGlobalConfig'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $row): array => [
                'id' => $row->id,
                'created_at' => $row->created_at?->format('d M Y, H:i'),
                'user_name' => $row->user_name ?? 'Sistem',
                'source' => match ($row->auditable_type) {
                    'LeaveApprovalChain' => 'Chain pegawai',
                    'LeavePybmcGlobalConfig' => 'PYBMC global',
                    default => 'Konfigurasi lama',
                },
                'event' => $row->event,
                'reason' => is_scalar($row->new_values['reason'] ?? null)
                    ? (string) $row->new_values['reason']
                    : '-',
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
            ])
            ->values()
            ->all();
    }
}
