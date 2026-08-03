<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListKepalaBagianEmployeesAction
{
    public function __construct(private readonly KepalaBagianScopeService $scope) {}

    public function execute(User $user, array $filters): LengthAwarePaginator
    {
        $today = now()->toDateString();
        $perPage = (int) ($filters['per_page'] ?? 10);

        return $this->scope->directReports($user)
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'jabatan_terakhir',
                'golongan_terakhir',
                'jenis_pegawai_id',
                'foto',
            ])
            ->with([
                'jenisPegawai:id,nama',
                'positionHistories' => fn ($query) => $query
                    ->with('unitKerja:id,nama')
                    ->where('is_latest', true)
                    ->orderByDesc('tmt_jabatan')
                    ->limit(1),
            ])
            ->withExists([
                'leaveRequests as sedang_cuti' => fn ($query) => $query
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $today)
                    ->whereDate('tanggal_selesai', '>=', $today),
            ])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $keyword = '%'.mb_strtolower(trim($search)).'%';
                $query->where(function ($employees) use ($keyword): void {
                    $employees->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                        ->orWhereRaw('lower(nip) like ?', [$keyword]);
                });
            })
            ->when(($filters['status'] ?? '') === 'aktif', fn ($query) => $query
                ->whereDoesntHave('leaveRequests', fn ($leaves) => $leaves
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $today)
                    ->whereDate('tanggal_selesai', '>=', $today)
                ))
            ->when(($filters['status'] ?? '') === 'cuti', fn ($query) => $query->whereHas('leaveRequests', fn ($leaves) => $leaves
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $today)
                ->whereDate('tanggal_selesai', '>=', $today)))

            ->when($filters['golongan'] ?? null, fn ($query, $val) => $query
                ->where('golongan_terakhir', 'LIKE', $val.'/%'))
            ->when($filters['unit_kerja_id'] ?? null, fn ($query, $val) => $query
                ->whereHas('positionHistories', fn ($ph) => $ph->where('is_latest', true)->where('unit_kerja_id', $val)))
            ->when($filters['jenis_pegawai_id'] ?? null, fn ($query, $val) => $query
                ->where('jenis_pegawai_id', $val))
            ->orderBy('nama_lengkap')
            ->paginate(in_array($perPage, [10, 25, 50], true) ? $perPage : 10)
            ->withQueryString()
            ->through(fn (Employee $employee): Employee => $this->withDisplayStatus($employee));
    }

    private function withDisplayStatus(Employee $employee): Employee
    {
        $employee->setAttribute('status_tampilan', (bool) $employee->getAttribute('sedang_cuti') ? 'Cuti' : 'Aktif');

        return $employee;
    }
}
