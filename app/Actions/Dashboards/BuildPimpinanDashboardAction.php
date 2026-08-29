<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\RankHistory;
use App\Models\User;
use App\Queries\Dashboards\ActiveEmployeeSummaryQuery;
use App\Queries\Dashboards\EmployeeTrendQuery;

class BuildPimpinanDashboardAction
{
    public function __construct(
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
        private readonly ActiveEmployeeSummaryQuery $employeeSummary,
        private readonly EmployeeTrendQuery $trenPegawai,
    ) {}

    public function execute(User $user): array
    {
        $employees = $this->employeeSummary->execute();
        $now = now();
        $pendingLeaves = $this->pendingLeaves($user->employee_id);
        $ews = $this->ewsAlerts->preview(5);
        $trenPegawai = collect($this->trenPegawai->monthlyActiveCounts($now));

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
            'totalPegawai' => $employees['total'],
            'komposisi' => $employees['composition'],
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
                        // Pangkat pertama pegawai tidak punya golongan asal, dan itu keadaan yang sah.
                        // Nilainya dibiarkan null; penanda teks seperti '-' akan lolos guard !empty()
                        // di view sehingga transisi golongan tampil seolah punya asal.
                        'golongan_awal' => $prevRank?->golongan?->kode,
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
            'totalEwsAktif' => $ews['total'],
            'ewsAktif' => $ews['alerts'],
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
            'distribusiGolongan' => $employees['rank_distribution'],
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
