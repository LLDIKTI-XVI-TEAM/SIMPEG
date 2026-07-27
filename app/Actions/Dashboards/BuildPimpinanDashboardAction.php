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
        $trenPegawai = collect(range(11, 0))
            ->map(function (int $offset) use ($now): array {
                $targetDate = $now->copy()->subMonths($offset)->endOfMonth();

                return [
                    'label' => $now->copy()->subMonths($offset)->translatedFormat('M Y'),
                    'jumlah' => Employee::query()
                        ->whereNotIn('status_aktif', ['Non-Aktif', 'Mutasi'])
                        ->whereDate('created_at', '<=', $targetDate)
                        ->where(function ($q) use ($targetDate): void {
                            $q->whereNull('tanggal_pensiun')
                                ->orWhereDate('tanggal_pensiun', '>', $targetDate);
                        })
                        ->count(),
                ];
            });

        $chartWidth = 440;
        $chartHeight = 100;
        $paddingX = 40;
        $paddingYBottom = 125;
        $count = $trenPegawai->count();
        $points = [];
        $pathD = '';
        $maxVal = 10;

        if ($count > 1) {
            $trenArray = $trenPegawai->toArray();
            $maxVal = max(array_column($trenArray, 'jumlah'));
            $maxVal = $maxVal > 0 ? $maxVal * 1.2 : 10;
            $stepX = $chartWidth / ($count - 1);

            foreach (array_values($trenArray) as $index => $data) {
                $x = $paddingX + ($index * $stepX);
                $y = $paddingYBottom - (($data['jumlah'] / $maxVal) * $chartHeight);
                $points[] = [
                    'x' => $x,
                    'y' => $y,
                    'val' => $data['jumlah'],
                    'label' => substr((string) $data['label'], 0, 3),
                ];
            }

            $pathD = 'M '.$points[0]['x'].' '.$points[0]['y'];
            for ($i = 0; $i < count($points) - 1; $i++) {
                $curr = $points[$i];
                $next = $points[$i + 1];
                $midX = ($curr['x'] + $next['x']) / 2;
                $pathD .= " C {$midX} {$curr['y']}, {$midX} {$next['y']}, {$next['x']} {$next['y']}";
            }
        }

        return [
            'totalPegawai' => $employees->count(),
            'komposisi' => $employees->countBy(fn (Employee $employee): string => $employee->jenisPegawai?->nama ?? 'Tidak Diketahui')->all(),
            'promotionRows' => RankHistory::query()
                ->with(['employee.rankHistories.golongan', 'golongan'])
                ->whereYear('tmt_pangkat', $now->year)
                ->whereMonth('tmt_pangkat', $now->month)
                ->orderBy('tmt_pangkat')
                ->limit(5)
                ->get()
                ->map(function (RankHistory $history): array {
                    $prevRank = $history->employee?->rankHistories
                        ?->where('tmt_pangkat', '<', $history->tmt_pangkat)
                        ->sortByDesc('tmt_pangkat')
                        ->first();

                    return [
                        'nama' => $history->employee?->nama_lengkap ?? '-',
                        'nip' => $history->employee?->nip ?? '-',
                        'golongan_awal' => $prevRank?->golongan?->kode ?? '-',
                        'golongan_tujuan' => $history->golongan?->kode ?? '-',
                        'golongan' => $history->golongan?->kode ?? '-',
                        'tmt' => $history->tmt_pangkat?->toDateString() ?? '-',
                        'no_sk' => $history->no_sk ?? '-',
                    ];
                }),
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
            'totalEwsAktif' => count($ews),
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
                ->map(fn (Employee $employee): string => (string) $employee->golongan_terakhir ?: 'Belum Diisi')
                ->countBy()
                ->sortKeys()
                ->all(),
            'trenPegawai' => $trenPegawai,
            'trendPoints' => $points,
            'trendPathD' => $pathD,
            'trendMaxVal' => $maxVal,
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
