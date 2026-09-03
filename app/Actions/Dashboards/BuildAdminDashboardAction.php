<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\RankHistory;
use App\Models\User;
use App\Queries\Dashboards\ActiveEmployeeSummaryQuery;
use App\Queries\Dashboards\EmployeeTrendQuery;

class BuildAdminDashboardAction
{
    public function __construct(
        private readonly ListActiveEwsAlertsAction $ewsAlerts,
        private readonly ActiveEmployeeSummaryQuery $employeeSummary,
        private readonly EmployeeTrendQuery $trenPegawai,
    ) {}

    /**
     * Menyusun seluruh data dashboard Admin: 7 widget real sesuai kontrak K-3.
     * Admin bukan approver cuti — tidak ada tombol Setuju/Tunda.
     *
     * Kontrak K-3 payload (key tidak boleh berubah tanpa koordinasi FE):
     *   - totalPegawaiAktif, komposisiPegawai, kenaikanPangkatBulanIni, kenaikanPangkatTahunIni
     *   - daftarKenaikanPangkat, cutiMenunggu, cutiDisetujuiBulanIni, cutiDitangguhkan
     *   - distribusiGolongan, auditTerbaru, trenPegawai
     *   - ewsAktif, totalEwsAktif, dashboardEwsUrgent, dashboardEwsWarning, dashboardEwsInfo
     *
     * Catatan golongan_awal: nilai null = pegawai belum punya pangkat sebelumnya.
     * Jangan menambahkan fallback string seperti '-' — itu akan lolos guard !empty() di view
     * dan menampilkan transisi golongan palsu. Pola yang benar: $prevRank?->golongan?->kode.
     *
     * @return array<string, mixed>
     */
    public function execute(?User $viewer = null): array
    {
        $now = now();
        $ews = $this->ewsAlerts->preview(5);
        $employees = $this->employeeSummary->execute($viewer);

        $daftarKenaikanPangkat = RankHistory::query()
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
                    // null = pegawai belum punya pangkat sebelumnya (bukan literal '-').
                    // View sudah menangani null dengan benar; jangan tambahkan ?? '-'.
                    'golongan_awal' => $prevRank?->golongan?->kode,
                    'golongan_tujuan' => $history->golongan?->kode ?? '-',
                    'golongan' => $history->golongan?->kode ?? '-',
                    'tmt' => $history->tmt_pangkat?->toDateString() ?? '-',
                    'no_sk' => $history->no_sk ?? '-',
                ];
            });

        $trenPegawai = collect($this->trenPegawai->monthlyActiveCounts($now));

        $auditTerbaru = AuditLog::query()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (AuditLog $audit): array => [
                'user' => $audit->user_name ?? 'Sistem',
                'aksi' => str_replace('_', ' ', $audit->event),
                'target' => $audit->new_values['nama_lengkap'] ?? class_basename($audit->auditable_type ?? 'Data'),
                'waktu' => $audit->created_at?->translatedFormat('d M Y H:i') ?? '-',
            ]);

        return [
            // W1: Ringkasan kepegawaian
            'totalPegawaiAktif' => $employees['total'],
            'komposisiPegawai' => $employees['composition'],

            // W2: Kenaikan pangkat
            'kenaikanPangkatBulanIni' => RankHistory::query()
                ->whereYear('tmt_pangkat', $now->year)
                ->whereMonth('tmt_pangkat', $now->month)
                ->count(),
            'kenaikanPangkatTahunIni' => RankHistory::query()
                ->whereYear('tmt_pangkat', $now->year)
                ->count(),
            'daftarKenaikanPangkat' => $daftarKenaikanPangkat,

            // W3: Statistik cuti (Admin hanya lihat, bukan approver)
            'cutiMenunggu' => LeaveRequest::query()->where('status', 'menunggu_approval')->count(),
            'cutiDisetujuiBulanIni' => LeaveRequest::query()
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $now->year)
                ->whereMonth('tanggal_mulai', $now->month)
                ->count(),
            'cutiDitangguhkan' => LeaveRequest::query()->where('status', 'ditangguhkan')->count(),

            // W4: Distribusi golongan
            'distribusiGolongan' => $employees['rank_distribution'],

            // W5: Audit log terbaru
            'auditTerbaru' => $auditTerbaru,

            // W6: Tren pegawai 12 bulan
            'trenPegawai' => $trenPegawai,

            // W7: EWS — Admin memonitor seluruh pegawai
            'dashboardEwsAlerts' => $ews['alerts'],
            'dashboardEwsTotal' => $ews['total'],
            'totalEwsAktif' => $ews['total'],
            'ewsAktif' => $ews['alerts'],
            'dashboardEwsUrgent' => $ews['urgent'],
            'dashboardEwsWarning' => $ews['warning'],
            'dashboardEwsInfo' => $ews['info'],
            'dashboardEwsLink' => route('ews'),
        ];
    }
}
