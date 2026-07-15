<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RankHistory;
use App\Models\User;

class BuildPimpinanDashboardAction
{
    public function __construct(private readonly ListActiveEwsAlertsAction $ewsAlerts) {}

    public function execute(User $user): array
    {
        $employees = Employee::query()
            ->with('jenisPegawai:id,nama')
            ->where('status_aktif', 'Aktif')
            ->get();
        $now = now();
        $pendingLeaves = $this->pendingLeaves($user->employee_id);
        $ews = $this->ewsAlerts->execute(null, null)['alerts'];

        return [
            'totalPegawai' => $employees->count(),
            'komposisi' => $employees->countBy(fn (Employee $employee): string => $employee->jenisPegawai?->nama ?? 'Tidak Diketahui')->all(),
            'promotionRows' => RankHistory::query()
                ->with(['employee', 'golongan'])
                ->whereYear('tmt_pangkat', $now->year)
                ->whereMonth('tmt_pangkat', $now->month)
                ->orderBy('tmt_pangkat')
                ->limit(5)
                ->get()
                ->map(fn (RankHistory $history): array => [
                    'nama' => $history->employee?->nama_lengkap ?? '-',
                    'nip' => $history->employee?->nip ?? '-',
                    'golongan' => $history->golongan?->kode ?? '-',
                    'tmt' => $history->tmt_pangkat?->toDateString() ?? '-',
                    'no_sk' => $history->no_sk ?? '-',
                ]),
            'naikPangkatBulanIni' => RankHistory::query()
                ->whereYear('tmt_pangkat', $now->year)
                ->whereMonth('tmt_pangkat', $now->month)
                ->count(),
            'naikPangkatTahunIni' => RankHistory::query()
                ->whereYear('tmt_pangkat', $now->year)
                ->count(),
            'cutiPending' => LeaveRequest::query()->where('status', 'menunggu_approval')->count(),
            'cutiDisetujuiBulanIni' => LeaveRequest::query()
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $now->year)
                ->whereMonth('tanggal_mulai', $now->month)
                ->count(),
            'cutiDitunda' => LeaveRequest::query()->where('status', 'ditangguhkan')->count(),
            'pendingLeaves' => $pendingLeaves,
            'ewsAktif' => array_slice($ews, 0, 5),
            'auditTerbaru' => AuditLog::query()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (AuditLog $audit): array => [
                    'user' => $audit->user_name ?? 'Sistem',
                    'aksi' => str_replace('_', ' ', $audit->event),
                    'target' => $audit->new_values['nama_lengkap'] ?? class_basename($audit->auditable_type ?? 'Data'),
                    'waktu' => $audit->created_at?->translatedFormat('d M Y H:i') ?? '-',
                ]),
            'distribusiGolongan' => $employees
                ->map(fn (Employee $employee): string => explode('/', (string) $employee->golongan_terakhir)[0] ?: 'Belum Diisi')
                ->countBy()
                ->sortKeys()
                ->all(),
            'trenPegawai' => collect(range(11, 0))
                ->map(fn (int $offset): array => [
                    'label' => $now->copy()->subMonths($offset)->translatedFormat('M Y'),
                    'jumlah' => Employee::query()
                        ->where('status_aktif', 'Aktif')
                        ->whereDate('created_at', '<=', $now->copy()->subMonths($offset)->endOfMonth())
                        ->count(),
                ]),
        ];
    }

    private function pendingLeaves(?string $employeeId)
    {
        if ($employeeId === null || $employeeId === '') {
            return collect();
        }

        return LeaveRequest::query()
            ->with(['employee', 'jenisCuti'])
            ->where('status', 'menunggu_approval')
            ->whereHas('steps', fn ($stepQuery) => $stepQuery
                ->where('status', 'active')
                ->where('approver_employee_id', $employeeId))
            ->orderBy('tanggal_mulai')
            ->limit(5)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'nama' => $leave->employee?->nama_lengkap ?? '-',
                'jenis' => $leave->jenisCuti?->nama ?? '-',
                'hari' => $leave->jumlah_hari_kerja,
                'mulai' => $leave->tanggal_mulai?->toDateString() ?? '-',
                'selesai' => $leave->tanggal_selesai?->toDateString() ?? '-',
            ]);
    }
}
