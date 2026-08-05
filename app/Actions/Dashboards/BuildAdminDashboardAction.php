<?php

namespace App\Actions\Dashboards;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RankHistory;

class BuildAdminDashboardAction
{
    public function __construct(private readonly ListActiveEwsAlertsAction $ewsAlerts) {}

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
    public function execute(): array
    {
        $now = now();
        $ews = $this->ewsAlerts->execute(null, null, null)['alerts'];

        $employees = Employee::query()
            ->with('jenisPegawai:id,nama')
            ->where('status_aktif', 'Aktif')
            ->get();

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

        $trenPegawai = collect(range(11, 0))
            ->map(function (int $offset) use ($now): array {
                $targetDate = $now->copy()->subMonths($offset)->endOfMonth();

                return [
                    'label' => $now->copy()->subMonths($offset)->translatedFormat('M Y'),
                    'jumlah' => Employee::query()
                        ->where('status_aktif', 'Aktif')
                        ->whereDate('created_at', '<=', $targetDate)
                        ->where(function ($q) use ($targetDate): void {
                            $q->whereNull('tanggal_pensiun')
                                ->orWhereDate('tanggal_pensiun', '>', $targetDate);
                        })
                        ->count(),
                ];
            });

        $distribusiGolongan = $employees
            ->map(fn (Employee $employee): string => (string) $employee->golongan_terakhir ?: 'Belum Diisi')
            ->countBy()
            ->sortKeys()
            ->all();

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
            'totalPegawaiAktif' => $employees->count(),
            'komposisiPegawai' => $employees->countBy(
                fn (Employee $employee): string => $employee->jenisPegawai?->nama ?? 'Tidak Diketahui'
            )->all(),

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
            'distribusiGolongan' => $distribusiGolongan,

            // W5: Audit log terbaru
            'auditTerbaru' => $auditTerbaru,

            // W6: Tren pegawai 12 bulan
            'trenPegawai' => $trenPegawai,

            // W7: EWS — Admin memonitor seluruh pegawai
            'dashboardEwsAlerts' => array_slice($ews, 0, 5),
            'dashboardEwsTotal' => count($ews),
            'totalEwsAktif' => count($ews),
            'ewsAktif' => array_slice($ews, 0, 5),
            'dashboardEwsUrgent' => collect($ews)->where('urgency', 'danger')->count(),
            'dashboardEwsWarning' => collect($ews)->where('urgency', 'warning')->count(),
            'dashboardEwsInfo' => collect($ews)->where('urgency', 'success')->count(),
            'dashboardEwsLink' => route('ews'),
        ];
    }
}
