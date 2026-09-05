<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mencari bawahan dan pengajuan cutinya tanpa pernah keluar dari scope bawahan langsung.
 */
class SearchKepalaBagianWorkspaceAction
{
    private const MAX_RESULTS_PER_GROUP = 5;

    public function __construct(private readonly KepalaBagianScopeService $scope) {}

    /**
     * @return array<string, list<array{title:string, subtitle:string, url:string}>>
     */
    public function execute(User $actor, ?string $query): array
    {
        $term = trim((string) $query);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $keyword = '%'.mb_strtolower($term).'%';
        $results = [];
        $employees = $this->scope->directReports($actor)
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir'])
            ->where(function (Builder $employees) use ($keyword): void {
                $employees->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('lower(nip) like ?', [$keyword]);
            })
            ->orderBy('nama_lengkap')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS_PER_GROUP)
            ->get();

        if ($employees->isNotEmpty()) {
            $results['Pegawai'] = $employees
                ->map(fn (Employee $employee): array => [
                    'title' => $employee->nama_lengkap,
                    'subtitle' => 'NIP: '.$employee->nip.' — '.($employee->jabatan_terakhir ?? '-'),
                    'url' => route('kepala-bagian.bawahan.show', $employee),
                ])
                ->values()
                ->all();
        }

        $directReportIds = $this->scope->directReports($actor)->select('employees.id');
        $leaves = LeaveRequest::query()
            ->select([
                'id',
                'employee_id',
                'jenis_cuti_id',
                'tanggal_mulai',
                'status',
                'alasan',
                'created_at',
            ])
            ->with([
                'employee:id,nama_lengkap,nip',
                'jenisCuti:id,nama',
            ])
            ->whereIn('employee_id', $directReportIds)
            ->where(function (Builder $leaves) use ($keyword): void {
                $leaves->whereRaw('lower(alasan) like ?', [$keyword])
                    ->orWhereHas('employee', function (Builder $employees) use ($keyword): void {
                        $employees->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                            ->orWhereRaw('lower(nip) like ?', [$keyword]);
                    });
            })
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS_PER_GROUP)
            ->get();

        if ($leaves->isNotEmpty()) {
            $results['Cuti'] = $leaves
                ->map(fn (LeaveRequest $leave): array => [
                    'title' => 'Pengajuan Cuti: '.($leave->employee?->nama_lengkap ?? 'Pegawai tidak tersedia'),
                    'subtitle' => ($leave->jenisCuti?->nama ?? 'Jenis cuti tidak tersedia')
                        .' — '.($leave->tanggal_mulai?->translatedFormat('d M Y') ?? '-')
                        .' — '.$this->statusLabel((string) $leave->status),
                    'url' => route('kepala-bagian.cuti.show', $leave),
                ])
                ->values()
                ->all();
        }

        return $results;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'disetujui' => 'Disetujui',
            'perlu_perubahan' => 'Perubahan',
            'ditangguhkan' => 'Ditangguhkan',
            LeaveRequest::STATUS_DUTY_POSTPONED => 'Ditangguhkan karena Tugas Dinas',
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => 'Dikembalikan karena Rollover',
            LeaveRequest::STATUS_CANCELLATION_PENDING => 'Menunggu Keputusan Pembatalan',
            LeaveRequest::STATUS_CANCELLED => 'Dibatalkan',
            'tidak_disetujui' => 'Tidak Disetujui',
            default => 'Menunggu Keputusan',
        };
    }
}
