<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Services\LeaveApprovalService;
use App\Support\Cuti\ApprovalStepLabel;

class ListPimpinanLeavesAction
{
    private const MAX_FILTER_OPTIONS = 100;

    public function __construct(private readonly EmployeeDashboardScopeService $employeeScope) {}

    /**
     * Scope diterapkan sebelum filter dan counter; assignment hanya mempersempit filter tindakan saya.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(User $user, array $filters): array
    {
        abort_unless($user->employee_id !== null && $user->employee?->isActive()
            && $user->hasPermission('cuti.read_all'), 403);

        $query = LeaveRequest::query()->with([
            'employee',
            'jenisCuti',
            'steps' => fn ($steps) => $steps
                ->select(['id', 'leave_request_id', 'step_type', 'role_label', 'status', 'step_order'])
                ->where('status', 'active')
                ->orderBy('step_order'),
        ])->whereIn('employee_id', $this->employeeScope->forIdentity($user)->select('employees.id'));

        // Base query untuk counter statistik
        $baseQuery = clone $query;

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->whereHas('employee', fn ($employees) => $employees
                ->where('nama_lengkap', 'like', $search)
                ->orWhere('nip', 'like', $search));
        }
        if (filled($filters['unit_kerja_id'] ?? null)) {
            $query->whereHas('employee.positionHistories', fn ($positions) => $positions
                ->where('is_latest', true)
                ->where('unit_kerja_id', $filters['unit_kerja_id']));
        }
        if (filled($filters['periode'] ?? null)) {
            $parts = explode('-', $filters['periode']);
            if (count($parts) === 2) {
                $query->whereYear('tanggal_mulai', $parts[0])->whereMonth('tanggal_mulai', $parts[1]);
            }
        }
        if (filled($filters['jenis_cuti_id'] ?? null)) {
            $query->where('jenis_cuti_id', $filters['jenis_cuti_id']);
        }
        if (filled($filters['status'] ?? null)) {
            if ($filters['status'] === 'menunggu_saya') {
                $query->whereIn('status', LeaveApprovalService::ACTIONABLE_STATUSES);
                if ($user->employee_id) {
                    $query->whereHas('steps', fn ($q) => $q
                        ->where('status', 'active')
                        ->where('approver_employee_id', $user->employee_id));
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($filters['status'] !== 'all') {
                $statuses = match ($filters['status']) {
                    'menunggu' => ['menunggu_approval'],
                    'perubahan' => ['perlu_perubahan'],
                    default => [$filters['status']],
                };
                $query->whereIn('status', $statuses);
            }
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $paginator = $query->latest()->orderByDesc('id')->paginate($perPage)->withQueryString();

        $paginator->getCollection()->transform(function (LeaveRequest $r): LeaveRequest {
            // Label memakai snapshot pengajuan, bukan nama approver atau konfigurasi terkini.
            $activeStep = $r->steps->first();
            $r->setAttribute(
                'current_step_label',
                $activeStep === null
                    ? null
                    : ApprovalStepLabel::display($activeStep->step_type, $activeStep->role_label),
            );

            return $r;
        });

        return [
            'leaves' => $paginator,
            'filters' => $filters,
            // Katalog bukan data pegawai; pilihan aktif tetap terlihat di dalam payload yang dibatasi.
            'jenisCutiOptions' => RefJenisCuti::query()
                ->when(filled($filters['jenis_cuti_id'] ?? null), fn ($types) => $types
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$filters['jenis_cuti_id']]))
                ->orderBy('nama')->orderBy('id')->limit(self::MAX_FILTER_OPTIONS)->get(['id', 'nama']),
            'unitKerjaOptions' => RefUnitKerja::query()
                ->when(filled($filters['unit_kerja_id'] ?? null), fn ($units) => $units
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$filters['unit_kerja_id']]))
                ->orderBy('nama')->orderBy('id')->limit(self::MAX_FILTER_OPTIONS)->get(['id', 'nama']),
            // Awal bulan mencegah tanggal 29–31 melompati Februari saat membentuk opsi periode.
            'optPeriodes' => collect(range(0, 11))
                ->map(fn (int $offset): string => now()->startOfMonth()->subMonths($offset)->format('Y-m')),
            // Counter memakai predikat actionable yang sama dengan filter menunggu_saya agar angka
            // tidak menghitung pengajuan yang hanya menyimpan step aktif sebagai snapshot.
            'menungguTindakanSaya' => (clone $baseQuery)
                ->whereIn('status', LeaveApprovalService::ACTIONABLE_STATUSES)
                ->whereHas('steps', fn ($q) => $q
                    ->where('status', 'active')
                    ->where('approver_employee_id', $user->employee_id)
                )->count(),
            'totalMenunggu' => (clone $baseQuery)->where('status', 'menunggu_approval')->count(),
            'totalDisetujui' => (clone $baseQuery)->where('status', 'disetujui')->count(),
            'totalDitangguhkan' => (clone $baseQuery)
                ->whereIn('status', ['ditangguhkan', LeaveRequest::STATUS_DUTY_POSTPONED, LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED])
                ->count(),
        ];
    }
}
