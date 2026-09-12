<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Support\Cuti\ApprovalStepLabel;
use App\Support\Cuti\CutiPeriodFilter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Membangun daftar pengajuan cuti dengan pagination/filtering di level database.
 * Memisahkan daftar milik sendiri dari monitoring berizin, menghitung counter ringkasan dari base query,
 * dan menyediakan langkah aktif dinamis dari snapshot leave_request_steps (menggantikan stage_atasan/stage_kepala).
 */
class ListLeaveRequestsAction
{
    private const MAX_FILTER_OPTIONS = 100;

    public function __construct(private readonly EmployeeDashboardScopeService $employeeScope) {}

    /**
     * @return array{
     *   riwayatCuti: LengthAwarePaginator,
     *   totalPengajuan: int, jumlahMenunggu: int, jumlahDisetujui: int, jumlahDitangguhkan: int,
     *   optJenisCutis: Collection<int|string, mixed>,
     *   optUnits: Collection<int|string, mixed>,
     *   optPeriodes: Collection<int, non-falsy-string>,
     *   optTahuns: Collection<int, string>,
     *   search: string, status: string, jenis: string, unit: string, periode: string,
     *   isPegawai: bool, ownScope: bool, canCreateLeave: bool, hasActiveFilters: bool
     * }
     */
    public function execute(User $user, Request $request): array
    {
        abort_unless($user->employee_id !== null && $user->employee?->isActive(), 403);

        // Jangkar ke awal bulan agar Februari tidak terlewati saat tanggal berjalan 29-31.
        $bulanBerjalan = now()->startOfMonth();
        // Parameter hanya dapat mempersempit; scope selain own tidak pernah menjadi grant monitoring.
        $ownScope = $request->query('scope') === 'own';
        $isPegawai = $ownScope || ! $user->hasPermission('cuti.read_all');
        $search = $isPegawai ? '' : trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $jenis = (string) $request->query('jenis', '');
        $unit = $isPegawai ? '' : (string) $request->query('unit', '');
        $periode = (string) $request->query('periode', '');
        $tahun = (string) $request->query('tahun', '');
        $perPage = min(max((int) $request->query('per_page', 10), 10), 50);
        $hasActiveFilters = $status !== ''
            || $jenis !== ''
            || $periode !== ''
            || $tahun !== ''
            || (! $isPegawai && ($search !== '' || $unit !== ''));

        $query = LeaveRequest::query()
            ->with([
                'employee',
                'jenisCuti',
                'steps' => fn ($steps) => $steps
                    ->select(['id', 'leave_request_id', 'step_type', 'role_label', 'status', 'step_order'])
                    ->where('status', 'active')
                    ->orderBy('step_order'),
            ])
            ->latest()
            ->orderByDesc('id');

        if ($isPegawai) {
            $query->where('employee_id', $user->employee_id);
        } else {
            // Permission efektif membuka monitoring, tetapi Switch Role tidak mengganti scope identitas.
            $query->whereIn('employee_id', $this->employeeScope->forIdentity($user)->select('employees.id'));
        }

        // Base query (setelah scope, sebelum filter status) menjadi sumber counter ringkasan.
        $baseQuery = clone $query;

        $statusMap = [
            'pending' => ['menunggu_approval'],
            'menunggu' => ['menunggu_approval'],
            'disetujui' => ['disetujui'],
            'ditunda' => ['ditangguhkan'],
            'ditangguhkan' => ['ditangguhkan'],
            LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED => [LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED],
            'ditangguhkan_tugas_dinas' => ['ditangguhkan_tugas_dinas'],
            LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER => [LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER],
            LeaveRequest::STATUS_CANCELLATION_PENDING => [LeaveRequest::STATUS_CANCELLATION_PENDING],
            LeaveRequest::STATUS_CANCELLED => [LeaveRequest::STATUS_CANCELLED],
            'perlu_perubahan' => ['perlu_perubahan'],
            'tidak_disetujui' => ['tidak_disetujui'],
        ];

        if ($status !== '' && isset($statusMap[$status])) {
            $query->whereIn('status', $statusMap[$status]);
        }
        if ($jenis !== '') {
            $query->whereHas('jenisCuti', fn ($jenisQuery) => $jenisQuery->where('nama', $jenis));
        }
        if ($unit !== '') {
            $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('jabatan_terakhir', $unit));
        }
        if ($periode !== '') {
            CutiPeriodFilter::parse($periode)?->applyToDateColumn($query, 'tanggal_mulai');
        }
        if ($tahun !== '' && ctype_digit($tahun) && strlen($tahun) === 4) {
            $query->whereYear('tanggal_mulai', (int) $tahun);
        }
        if ($search !== '') {
            $query->whereHas('employee', function ($employeeQuery) use ($search): void {
                $employeeQuery->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $riwayatCuti = $query->paginate($perPage)->withQueryString();
        $riwayatCuti->getCollection()->transform(fn (LeaveRequest $r): array => $this->mapRow($r));

        $currentYear = (int) date('Y');
        $optTahuns = collect(range($currentYear + 1, $currentYear - 3))->map(fn ($y) => (string) $y);

        return [
            'riwayatCuti' => $riwayatCuti,
            'totalPengajuan' => (clone $baseQuery)->count(),
            'jumlahMenunggu' => (clone $baseQuery)->where('status', 'menunggu_approval')->count(),
            'jumlahDisetujui' => (clone $baseQuery)->where('status', 'disetujui')->count(),
            'jumlahDitangguhkan' => (clone $baseQuery)
                ->whereIn('status', ['ditangguhkan', 'ditangguhkan_tugas_dinas', LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED])
                ->count(),
            'optJenisCutis' => RefJenisCuti::query()
                ->when($jenis !== '', fn ($types) => $types
                    ->orderByRaw('CASE WHEN nama = ? THEN 0 ELSE 1 END', [$jenis]))
                ->orderBy('nama')->limit(self::MAX_FILTER_OPTIONS)->pluck('nama'),
            'optUnits' => $isPegawai
                ? collect()
                : $this->employeeScope->forIdentity($user)
                    ->whereNotNull('jabatan_terakhir')
                    ->groupBy('jabatan_terakhir')
                    ->when($unit !== '', fn ($employees) => $employees
                        ->orderByRaw('CASE WHEN jabatan_terakhir = ? THEN 0 ELSE 1 END', [$unit]))
                    ->orderBy('jabatan_terakhir')
                    ->limit(self::MAX_FILTER_OPTIONS)
                    ->pluck('jabatan_terakhir'),
            // Portable periode options (verified current producer): 12 bulan terakhir, tanpa SQL PostgreSQL-only.
            'optPeriodes' => collect(range(0, 11))
                ->map(fn (int $offset): string => $bulanBerjalan->copy()->subMonths($offset)->format('Y-m')),
            'optTahuns' => $this->tahunOptions($baseQuery),
            'search' => $search,
            'status' => $status,
            'jenis' => $jenis,
            'unit' => $unit,
            'periode' => $periode,
            'tahun' => $tahun,
            'isPegawai' => $isPegawai,
            'ownScope' => $ownScope,
            'canCreateLeave' => $user->getEffectiveRole() !== 'super_admin'
                && $user->hasPermission('cuti.create') && ! $user->employee?->is_kepala_lembaga,
            'hasActiveFilters' => $hasActiveFilters,
        ];
    }

    /**
     * Opsi tahun mencakup rentang berurutan antara pengajuan terawal dan terakhir yang berada dalam scope
     * pengguna, sehingga tidak membocorkan keberadaan data pegawai lain. Tahun berjalan selalu disertakan
     * agar filter tetap berguna saat belum ada pengajuan sama sekali.
     *
     * Rentang berurutan dipilih, bukan daftar tahun yang benar-benar berisi, karena mengambil tahun distinct
     * menuntut fungsi tanggal khas satu basis data sedangkan opsi periode pada halaman ini sengaja dijaga
     * portabel. Konsekuensinya tahun tanpa pengajuan dapat muncul sebagai opsi, sama seperti opsi bulan yang
     * juga menawarkan dua belas bulan terakhir tanpa memandang ada tidaknya data.
     *
     * @param  Builder<LeaveRequest>  $baseQuery
     * @return Collection<int, string>
     */
    private function tahunOptions(Builder $baseQuery): Collection
    {
        // reorder() melepas urutan default; agregat tanpa GROUP BY tidak boleh membawa ORDER BY kolom lain di PostgreSQL.
        $terawal = (clone $baseQuery)->reorder()->min('tanggal_mulai');
        $terakhir = (clone $baseQuery)->reorder()->max('tanggal_mulai');

        $tahunSekarang = (int) now()->year;
        $tahunAwal = $terawal !== null ? (int) CarbonImmutable::parse((string) $terawal)->year : $tahunSekarang;
        $tahunAkhir = $terakhir !== null ? (int) CarbonImmutable::parse((string) $terakhir)->year : $tahunSekarang;

        $tahunAwal = min($tahunAwal, $tahunSekarang);
        $tahunAkhir = max($tahunAkhir, $tahunSekarang);

        /** @var list<string> $tahunTerurut */
        $tahunTerurut = array_map('strval', range($tahunAkhir, $tahunAwal));

        return collect($tahunTerurut);
    }

    /**
     * Bentuk baris daftar: pertahankan status runtime mentah dan tambahkan langkah aktif dinamis.
     * Tidak lagi memancarkan slot tetap stage_atasan/stage_kepala.
     *
     * @return array<string, mixed>
     */
    private function mapRow(LeaveRequest $r): array
    {
        $activeStep = $r->steps->firstWhere('status', 'active');

        return [
            'id' => $r->id,
            'nama' => $r->employee?->nama_lengkap ?? '-',
            'nip' => $r->employee?->nip ?? '-',
            'unit' => $r->employee?->jabatan_terakhir ?? '-',
            'jenis' => $r->jenisCuti?->nama ?? '-',
            'mulai' => optional($r->tanggal_mulai)->toDateString(),
            'selesai' => optional($r->tanggal_selesai)->toDateString(),
            'hari' => $r->jumlah_hari_kerja,
            'alasan' => $r->alasan,
            // Status runtime mentah dipertahankan agar Blade dapat memetakan lifecycle tanpa mengubah kontrak list.
            'status' => $r->status,
            // Label berasal dari snapshot agar perubahan konfigurasi tidak mengubah riwayat pengajuan.
            'current_step_label' => $activeStep === null
                ? null
                : ApprovalStepLabel::display($activeStep->step_type, $activeStep->role_label),
            'periode' => optional($r->tanggal_mulai)->format('Y-m'),
        ];
    }
}
