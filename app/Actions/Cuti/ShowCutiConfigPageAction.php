<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Menyiapkan data terbatas untuk halaman konfigurasi chain cuti.
 * Pencarian pegawai dan kandidat approver dibatasi agar halaman admin tidak memuat seluruh data pegawai ke browser.
 */
class ShowCutiConfigPageAction
{
    private const TARGET_EMPLOYEE_LIMIT = 50;

    private const APPROVER_LIMIT = 50;

    public function __construct(private readonly EmployeeDashboardScopeService $employeeScope) {}

    /**
     * Menampilkan kandidat pegawai sesuai pencarian serta detail chain pegawai yang dipilih.
     * Kepala Bagian dihitung di server dari relasi yang sama dengan validasi request agar UI bukan sumber kebenaran.
     *
     * @return array<string, mixed>
     */
    public function execute(
        User $actor,
        ?string $search,
        ?string $selectedEmployeeId,
        ?string $approverSearch,
        mixed $oldSteps = null,
        string $requestedTab = 'pegawai',
        string $requestedStep = 'susun',
        mixed $oldGlobalPybmcId = null,
        mixed $oldKepalaBagianId = null,
    ): array {
        $targetEmployees = $this->targetEmployees($actor, $search);
        $selectedEmployee = $this->selectedEmployee($actor, $selectedEmployeeId);
        $activeChain = $this->activeChain($selectedEmployee);
        $globalPybmc = LeavePybmcGlobalConfig::query()
            ->select(['id', 'approver_employee_id', 'effective_from'])
            ->with('approver:id,nama_lengkap,nip')
            ->latestRevision()
            ->first();
        $selectedKepalaBagian = $this->selectedKepalaBagian($selectedEmployee);
        $hasGlobalIdentityScope = $this->employeeScope->hasGlobalIdentityScope($actor);
        $canViewAudit = $actor->hasPermission('audit_logs.read');

        return [
            'search' => $search,
            'targetEmployees' => $targetEmployees,
            'selectedEmployee' => $selectedEmployee,
            'selectedKepalaBagian' => $selectedKepalaBagian,
            'canAssignKepalaBagian' => $this->canAssignKepalaBagian($actor, $selectedEmployee),
            'hasGlobalIdentityScope' => $hasGlobalIdentityScope,
            'canViewAudit' => $canViewAudit,
            'initialTab' => $this->initialTab($requestedTab, $hasGlobalIdentityScope, $canViewAudit),
            'initialStep' => in_array($requestedStep, ['susun', 'pilih', 'tinjau'], true) ? $requestedStep : 'susun',
            'oldGlobalPybmcLabel' => $this->oldActiveEmployeeLabel(
                $oldGlobalPybmcId,
                $hasGlobalIdentityScope,
            ),
            'oldKepalaBagianLabel' => $this->oldActiveEmployeeLabel(
                $oldKepalaBagianId,
                $this->canAssignKepalaBagian($actor, $selectedEmployee),
                $selectedEmployee?->id,
            ),
            'approverSearch' => $approverSearch,
            'approverCandidates' => $this->approverCandidates($approverSearch, $activeChain, $globalPybmc, $oldSteps),
            'initialVerifierSteps' => $this->initialVerifierSteps($activeChain),
            'initialPybmcEmployeeId' => $this->initialPybmcEmployeeId($activeChain),
            'auditRows' => $this->auditRows($actor),
            'chainStats' => [
                'active' => LeaveApprovalChain::query()
                    ->whereIn('employee_id', $this->identityScope($actor)->select('employees.id'))
                    ->where('is_active', true)
                    ->count(),
            ],
            'globalPybmc' => $globalPybmc,
            'unitKerjaOptions' => $this->unitKerjaOptions(),
        ];
    }

    /**
     * Tab privat hanya boleh aktif bila capability dan scope backend mengizinkan;
     * query URL tidak pernah menjadi sumber otorisasi panel.
     */
    private function initialTab(string $requestedTab, bool $hasGlobalIdentityScope, bool $canViewAudit): string
    {
        return match ($requestedTab) {
            'rangkaian' => 'rangkaian',
            'pybmc' => $hasGlobalIdentityScope ? 'pybmc' : 'pegawai',
            'riwayat' => $canViewAudit ? 'riwayat' : 'pegawai',
            default => 'pegawai',
        };
    }

    /**
     * Memulihkan label old-input hanya dari UUID pegawai aktif yang masih boleh
     * dipakai pada surface terkait; UUID malformed tidak pernah diteruskan ke PostgreSQL.
     */
    private function oldActiveEmployeeLabel(mixed $employeeId, bool $allowed, ?string $excludedId = null): string
    {
        if (! $allowed || ! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return '';
        }

        $query = Employee::query()
            ->select(['id', 'nama_lengkap', 'nip'])
            ->whereActiveStatus()
            ->whereKey($employeeId);

        if ($excludedId !== null) {
            $query->whereKeyNot($excludedId);
        }

        $employee = $query->first();

        if ($employee === null) {
            return '';
        }

        return $employee->nama_lengkap.' ('.$employee->nip.')';
    }

    /**
     * Membaca Kepala Bagian dari penugasan bertanggal yang efektif hari ini.
     * Pointer snapshot pegawai tidak dipakai agar penugasan lama atau mendatang tidak bocor ke chain baru.
     */
    private function selectedKepalaBagian(?Employee $selectedEmployee): ?Employee
    {
        $currentSupervisor = $selectedEmployee?->currentSupervisor();

        if ($currentSupervisor === null) {
            return null;
        }

        $kepalaBagian = $currentSupervisor->kepalaBagian()
            ->first(['id', 'nama_lengkap', 'nip']);

        return $kepalaBagian instanceof Employee ? $kepalaBagian : null;
    }

    /**
     * Menyiapkan flag presentasi di Action supaya Blade tidak menjalankan query permission saat render.
     * Gate backend pada route dan FormRequest tetap menjadi otorisasi utama.
     */
    private function canAssignKepalaBagian(User $actor, ?Employee $selectedEmployee): bool
    {
        return $selectedEmployee !== null
            && in_array($actor->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true)
            && $actor->hasPermission('employees.update');
    }

    /** @return Collection<int, RefUnitKerja> */
    private function unitKerjaOptions(): Collection
    {
        return RefUnitKerja::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nama']);
    }

    /** @return Collection<int, Employee> */
    private function targetEmployees(User $actor, ?string $search): Collection
    {
        if ($search === null || trim($search) === '') {
            return collect();
        }

        $keyword = '%'.mb_strtolower(trim($search)).'%';

        return $this->identityScope($actor)
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir', 'kepala_bagian_id'])
            ->whereActiveStatus()
            ->where(function ($query) use ($keyword): void {
                $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('lower(nip) like ?', [$keyword]);
            })
            ->orderBy('nama_lengkap')
            ->limit(self::TARGET_EMPLOYEE_LIMIT)
            ->get();
    }

    private function selectedEmployee(User $actor, ?string $selectedEmployeeId): ?Employee
    {
        if ($selectedEmployeeId === null || $selectedEmployeeId === '') {
            return null;
        }

        $employee = $this->identityScope($actor)
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir', 'kepala_bagian_id'])
            ->whereActiveStatus()
            ->find($selectedEmployeeId);

        abort_if($employee === null, 404);

        return $employee;
    }

    /** @return Collection<int, Employee> */
    private function approverCandidates(
        ?string $search,
        ?LeaveApprovalChain $activeChain,
        ?LeavePybmcGlobalConfig $globalPybmc,
        mixed $oldSteps,
    ): Collection {
        $trustedIds = ($activeChain?->steps?->pluck('approver_employee_id') ?? collect())
            ->push($globalPybmc?->approver_employee_id)
            ->filter()
            ->unique()
            ->values();
        $oldInputIds = $this->oldApproverIds($oldSteps);

        $activeCandidates = collect();

        if ($search !== null && trim($search) !== '') {
            $keyword = '%'.mb_strtolower(trim($search)).'%';
            $activeCandidates = Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->whereActiveStatus()
                ->where(function ($query) use ($keyword): void {
                    $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                        ->orWhereRaw('lower(nip) like ?', [$keyword]);
                })
                ->orderBy('nama_lengkap')
                ->limit(self::APPROVER_LIMIT)
                ->get()
                ->each(fn (Employee $employee) => $employee->setAttribute('is_selectable', true));
        }

        $activeTrustedIds = $trustedIds->isEmpty()
            ? collect()
            : Employee::query()
                ->whereIn('id', $trustedIds)
                ->whereActiveStatus()
                ->pluck('id');
        $trustedCandidates = $trustedIds->isEmpty()
            ? collect()
            : Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->whereIn('id', $trustedIds)
                ->get()
                ->each(fn (Employee $employee) => $employee->setAttribute(
                    'is_selectable',
                    $activeTrustedIds->contains($employee->id),
                ));
        $oldInputCandidates = $oldInputIds->isEmpty()
            ? collect()
            : Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->whereActiveStatus()
                ->whereIn('id', $oldInputIds)
                ->get()
                ->each(fn (Employee $employee) => $employee->setAttribute('is_selectable', true));

        return $trustedCandidates
            ->merge($oldInputCandidates)
            ->merge($activeCandidates)
            ->unique('id')
            ->sortBy('nama_lengkap')
            ->values();
    }

    /** @return Collection<int, non-empty-string> */
    private function oldApproverIds(mixed $oldSteps): Collection
    {
        if (! is_array($oldSteps)) {
            return collect();
        }

        // Form menerima maksimal sepuluh tahap; old input dibatasi dengan kontrak yang sama
        // agar redirect validasi tidak memperluas query kandidat pegawai.
        return collect($oldSteps)
            ->filter(fn (mixed $step): bool => is_array($step))
            ->take(10)
            ->map(fn (array $step): mixed => $step['approver_employee_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
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
     * }>
     */
    private function auditRows(User $actor): array
    {
        if (! $actor->hasPermission('audit_logs.read')) {
            return [];
        }

        $query = AuditLog::query()
            ->select(['id', 'user_name', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at']);

        if ($this->employeeScope->hasGlobalIdentityScope($actor)) {
            $query
            // Penerapan template ke unit melekat pada unit kerja, jadi barisnya dibatasi pada event
            // konfigurasi supaya perubahan data master unit kerja tidak ikut masuk ke log ini.
                ->where(function ($query): void {
                    $query->whereIn('auditable_type', ['ApprovalConfig', 'LeaveApprovalChain', 'LeavePybmcGlobalConfig'])
                        ->orWhere(function ($unit): void {
                            $unit->where('auditable_type', 'RefUnitKerja')
                                ->where('event', 'CONFIG_UPDATE');
                        });
                });
        } else {
            $chainIds = LeaveApprovalChain::query()
                ->select('leave_approval_chains.id')
                ->whereIn('employee_id', $this->identityScope($actor)->select('employees.id'));

            $query->where('auditable_type', 'LeaveApprovalChain')
                ->whereIn('auditable_id', $chainIds);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $row): array => [
                'id' => $row->id,
                'created_at' => $row->created_at?->format('d M Y, H:i'),
                'user_name' => $row->user_name ?? 'Sistem',
                'source' => match ($row->auditable_type) {
                    'LeaveApprovalChain' => 'Chain pegawai',
                    'LeavePybmcGlobalConfig' => 'PYBMC global',
                    'RefUnitKerja' => 'Template unit',
                    default => 'Konfigurasi lama',
                },
                'event' => $row->event,
                'reason' => is_scalar($row->new_values['reason'] ?? null)
                    ? (string) $row->new_values['reason']
                    : '-',
            ])
            ->values()
            ->all();
    }

    /** @return Builder<Employee> */
    private function identityScope(User $actor): Builder
    {
        return $this->employeeScope->forIdentity($actor);
    }
}
