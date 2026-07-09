<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RejectLeaveAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApproveLeaveRequest;
use App\Http\Requests\Cuti\PostponeLeaveRequest;
use App\Http\Requests\Cuti\ResubmitLeaveRequestRequest;
use App\Http\Requests\Cuti\ReviewLeaveDecisionRequest;
use App\Http\Requests\Cuti\StoreLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\LeaveApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CutiController extends Controller
{
    /**
     * Data contoh lama yang masih dikonsumsi closure laporan export cuti di web.php.
     * Dipertahankan sementara hingga fitur laporan/export cuti dimigrasi ke data nyata pada slice tersendiri;
     * method index/show/store di bawah sudah membaca data nyata dari basis data.
     *
     * @var array<int, array<string, mixed>>
     */
    public static $riwayatCuti = [
        [
            'id' => 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'unit' => 'Bag. Umum',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-20',
            'selesai' => '2026-06-24',
            'hari' => 5,
            'status' => 'menunggu',
            'tgl_pengajuan' => '2026-06-18',
            'alasan' => 'Acara keluarga di luar kota',
            'stage_atasan' => 'menunggu',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026',
        ],
        [
            'id' => 2,
            'nama' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'unit' => 'Bag. Keuangan',
            'jenis' => 'Cuti Sakit',
            'mulai' => '2026-04-10',
            'selesai' => '2026-04-12',
            'hari' => 3,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2026-04-09',
            'alasan' => 'Sakit demam berdarah',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'April 2026',
        ],
        [
            'id' => 3,
            'nama' => 'Sabrina Rossa Adriani Wibowo',
            'nip' => '20261210820500 0 04',
            'unit' => 'Bag. SDM',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-02-01',
            'selesai' => '2026-02-05',
            'hari' => 5,
            'status' => 'ditunda',
            'tgl_pengajuan' => '2026-01-28',
            'alasan' => 'Menunggu konfirmasi pengganti tugas',
            'stage_atasan' => 'ditunda',
            'stage_kepala' => 'menunggu',
            'periode' => 'Februari 2026',
        ],
        [
            'id' => 4,
            'nama' => 'Cimma Sari Oktariani Di Silapu',
            'nip' => '26110820520600 0 04',
            'unit' => 'Bag. IT',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-22',
            'selesai' => '2026-06-26',
            'hari' => 5,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2026-06-19',
            'alasan' => 'Cuti liburan tahunan',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'Juni 2026',
        ],
        [
            'id' => 5,
            'nama' => 'Nurarningsih Dumbea, S.P.',
            'nip' => '19880123202 1 005',
            'unit' => 'Bag. Umum',
            'jenis' => 'Cuti Melahirkan',
            'mulai' => '2025-10-01',
            'selesai' => '2025-12-29',
            'hari' => 90,
            'status' => 'disetujui',
            'tgl_pengajuan' => '2025-09-15',
            'alasan' => 'Persalinan anak pertama',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'disetujui',
            'periode' => 'Oktober 2025',
        ],
        [
            'id' => 6,
            'nama' => 'Nadia Kusuma',
            'nip' => '19950822202001 2 002',
            'unit' => 'Bag. SDM',
            'jenis' => 'Cuti Sakit',
            'mulai' => '2026-06-25',
            'selesai' => '2026-06-27',
            'hari' => 3,
            'status' => 'menunggu',
            'tgl_pengajuan' => '2026-06-23',
            'alasan' => 'Sakit migrain berat',
            'stage_atasan' => 'disetujui',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026',
        ],
        [
            'id' => 7,
            'nama' => 'Yucna Dara, S.P., M.M.',
            'nip' => '19840120099 2 002',
            'unit' => 'Bag. Keuangan',
            'jenis' => 'Cuti Tahunan',
            'mulai' => '2026-06-27',
            'selesai' => '2026-07-01',
            'hari' => 5,
            'status' => 'ditunda',
            'tgl_pengajuan' => '2026-06-24',
            'alasan' => 'Ada audit internal keuangan',
            'stage_atasan' => 'ditunda',
            'stage_kepala' => 'menunggu',
            'periode' => 'Juni 2026',
        ],
    ];

    public function rekap(Request $request)
    {
        $periode = $request->query('periode');
        $unit = $request->query('unit');
        $pegawaiId = $request->query('pegawai');
        $jenisId = $request->query('jenis');

        if (is_array($pegawaiId)) {
            abort(404);
        }

        $pegawaiId = trim((string) $pegawaiId);
        if ($pegawaiId !== '' && ! Str::isUuid($pegawaiId)) {
            abort(404);
        }
        $pegawaiId = $pegawaiId === '' ? null : $pegawaiId;

        $balancesQuery = LeaveBalance::with('employee');

        if ($unit) {
            $balancesQuery->whereHas('employee', function ($q) use ($unit) {
                $q->where('jabatan_terakhir', $unit);
            });
        }
        if ($pegawaiId) {
            $balancesQuery->where('employee_id', $pegawaiId);
        }
        // Periode usually refers to 'tahun' in LeaveBalance if it's just a year
        if ($periode && is_numeric($periode)) {
            $balancesQuery->where('tahun', $periode);
        }

        $totalPegawai = Employee::where('status_aktif', 'Aktif')->count();
        $cutiTerpakai = (clone $balancesQuery)->sum('terpakai');
        $sisaSaldo = (clone $balancesQuery)->sum('sisa');
        $saldoKritis = (clone $balancesQuery)->where('sisa', '<=', 3)->count();

        $summary = [
            ['label' => 'Total Pegawai', 'value' => $totalPegawai, 'caption' => 'Pegawai aktif', 'tone' => 'primary'],
            ['label' => 'Cuti Terpakai', 'value' => $cutiTerpakai, 'caption' => 'Hari kerja tahun ini', 'tone' => 'info'],
            ['label' => 'Sisa Saldo', 'value' => $sisaSaldo, 'caption' => 'Akumulasi hari', 'tone' => 'success'],
            ['label' => 'Saldo Kritis', 'value' => $saldoKritis, 'caption' => 'Sisa <= 3 hari', 'tone' => 'danger'],
        ];

        $leaveBalances = (clone $balancesQuery)->paginate(10, ['*'], 'page_saldo')->withQueryString();
        $leaveBalances->getCollection()->transform(function ($b) {
            $status = 'Aman';
            if ($b->sisa <= 3) {
                $status = 'Kritis';
            } elseif ($b->sisa <= 6) {
                $status = 'Perhatian';
            }

            return [
                'employee_id' => $b->employee_id,
                'tahun' => $b->tahun,
                'nama' => $b->employee?->nama_lengkap ?? '-',
                'nip' => $b->employee?->nip ?? '-',
                'unit' => $b->employee?->jabatan_terakhir ?? '-',
                'jatah' => $b->jatah_awal,
                'carry' => $b->carry_over,
                'sisa_n2' => $b->sisa_n2,
                'sisa_n1' => $b->sisa_n1,
                'sisa_tahun_berjalan' => $b->sisa_tahun_berjalan,
                'hangus' => $b->hangus,
                'terpakai' => $b->terpakai,
                'sisa' => $b->sisa,
                'tahunan' => $b->terpakai,
                'sakit' => 0,
                'lain' => 0,
                'status' => $status,
            ];
        });

        $requestsQuery = LeaveRequest::with(['employee', 'jenisCuti'])->latest();

        if ($unit) {
            $requestsQuery->whereHas('employee', function ($q) use ($unit) {
                $q->where('jabatan_terakhir', $unit);
            });
        }
        if ($pegawaiId) {
            $requestsQuery->where('employee_id', $pegawaiId);
        }
        if ($jenisId) {
            $requestsQuery->where('jenis_cuti_id', $jenisId);
        }
        if ($periode) {
            if (is_numeric($periode)) {
                $requestsQuery->whereYear('tanggal_mulai', $periode);
            } else {
                // simple match for something like "Juni 2026"
                $months = ['Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6, 'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12];
                $parts = explode(' ', $periode);
                if (count($parts) === 2) {
                    $m = $months[$parts[0]] ?? null;
                    $y = $parts[1];
                    if ($m && $y) {
                        $requestsQuery->whereMonth('tanggal_mulai', $m)->whereYear('tanggal_mulai', $y);
                    }
                }
            }
        }

        $usageRows = (clone $requestsQuery)->paginate(10, ['*'], 'page_usage')->withQueryString();
        $usageRows->getCollection()->transform(function ($r) {
            return [
                'nama' => $r->employee?->nama_lengkap ?? '-',
                'nip' => $r->employee?->nip ?? '-',
                'jenis' => $r->jenisCuti?->nama ?? '-',
                'mulai' => optional($r->tanggal_mulai)->format('d M Y'),
                'selesai' => optional($r->tanggal_selesai)->format('d M Y'),
                'hari' => $r->jumlah_hari_kerja,
                'status' => $r->status,
            ];
        });

        $selectedEmployee = $pegawaiId ? Employee::find($pegawaiId) : null;
        $selectedBalance = $selectedEmployee === null ? null : LeaveBalance::query()
            ->where('employee_id', $selectedEmployee->id)
            ->when($periode && is_numeric($periode), fn ($query) => $query->where('tahun', (int) $periode))
            ->orderByDesc('tahun')
            ->first();
        $ledgerRows = LeaveBalanceLedger::query()
            ->when(
                $selectedEmployee !== null,
                fn ($query) => $query->where('employee_id', $selectedEmployee->id),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'page_ledger')
            ->withQueryString();
        $rolloverRows = LeaveBalanceLedger::query()
            ->when(
                $selectedEmployee !== null,
                fn ($query) => $query->where('employee_id', $selectedEmployee->id),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->whereIn('event_type', ['rollover_applied', 'carry_over_granted', 'carry_over_expired'])
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get();

        return view('admin.cuti.rekap', compact(
            'summary', 'leaveBalances', 'usageRows',
            'periode', 'unit', 'pegawaiId', 'jenisId',
            'selectedEmployee', 'selectedBalance', 'ledgerRows', 'rolloverRows'
        ));
    }

    /**
     * Menampilkan daftar pengajuan cuti.
     * Pegawai biasa hanya melihat pengajuannya sendiri; peran dengan hak memantau melihat seluruh pengajuan.
     */
    public function index(Request $request)
    {
        $user = request()->user();
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $jenis = (string) $request->query('jenis', '');
        $unit = (string) $request->query('unit', '');
        $periode = (string) $request->query('periode', '');
        $perPage = min(max((int) $request->query('per_page', 10), 10), 50);

        $query = LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'steps'])
            ->latest();

        // Pemantau (mis. admin kepegawaian/pimpinan) boleh melihat semua; selain itu dibatasi milik sendiri.
        if (! $user->hasPermission('cuti.read_all')) {
            $query->where('employee_id', $user->employee_id);
        }

        $baseQuery = clone $query;

        $statusMap = [
            'pending' => ['menunggu_approval'],
            'menunggu' => ['menunggu_approval'],
            'disetujui' => ['disetujui'],
            'ditunda' => ['ditangguhkan'],
            'ditangguhkan' => ['ditangguhkan'],
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
            $parts = explode('-', $periode);
            if (count($parts) === 2) {
                $query->whereYear('tanggal_mulai', $parts[0])->whereMonth('tanggal_mulai', $parts[1]);
            }
        }

        if ($search !== '') {
            $query->whereHas('employee', function ($employeeQuery) use ($search): void {
                $employeeQuery->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $riwayatCuti = $query->paginate($perPage)->withQueryString();
        $riwayatCuti->getCollection()->transform(fn (LeaveRequest $r): array => $this->mapCutiRow($r));

        $totalPengajuan = (clone $baseQuery)->count();
        $jumlahMenunggu = (clone $baseQuery)->where('status', 'menunggu_approval')->count();
        $jumlahDisetujui = (clone $baseQuery)->where('status', 'disetujui')->count();
        $jumlahDitangguhkan = (clone $baseQuery)->where('status', 'ditangguhkan')->count();

        $optJenisCutis = RefJenisCuti::orderBy('nama')->pluck('nama');
        $optUnits = Employee::query()
            ->whereNotNull('jabatan_terakhir')
            ->distinct()
            ->orderBy('jabatan_terakhir')
            ->pluck('jabatan_terakhir');
        $optPeriodes = collect(range(0, 11))
            ->map(fn (int $offset): string => now()->subMonths($offset)->format('Y-m'));

        return view('admin.cuti.index', compact(
            'riwayatCuti',
            'totalPengajuan',
            'jumlahMenunggu',
            'jumlahDisetujui',
            'jumlahDitangguhkan',
            'optJenisCutis',
            'optUnits',
            'optPeriodes',
            'search',
            'status',
            'jenis',
            'unit',
            'periode',
        ));
    }

    /**
     * Membentuk baris tabel cuti dari model yang sudah dipaginasi oleh database.
     *
     * @return array<string, mixed>
     */
    private function mapCutiRow(LeaveRequest $r): array
    {
        $activeStep = $r->steps->firstWhere('status', 'active');
        $approvedSteps = $r->steps->where('status', 'approved')->count();

        return [
            'id' => $r->id,
            'nama' => $r->employee?->nama_lengkap ?? '-',
            'nip' => $r->employee?->nip ?? '-',
            'unit' => $r->employee?->jabatan_terakhir ?? '-',
            'jenis' => $r->jenisCuti?->nama ?? '-',
            'mulai' => optional($r->tanggal_mulai)->toDateString(),
            'selesai' => optional($r->tanggal_selesai)->toDateString(),
            'hari' => $r->jumlah_hari_kerja,
            'status' => match ($r->status) {
                'disetujui' => 'disetujui',
                'ditangguhkan' => 'ditunda',
                'perlu_perubahan' => 'perlu_perubahan',
                'tidak_disetujui' => 'tidak_disetujui',
                default => 'menunggu',
            },
            'alasan' => $r->alasan,
            'stage_atasan' => $approvedSteps > 0 ? 'disetujui' : ($r->status === 'ditangguhkan' ? 'ditunda' : 'menunggu'),
            'stage_kepala' => $r->status === 'disetujui' ? 'disetujui' : ($activeStep?->role_label ?? 'menunggu'),
            'periode' => optional($r->tanggal_mulai)->format('Y-m'),
        ];
    }

    /**
     * Menampilkan form pengajuan cuti baru.
     */
    public function create()
    {
        $user = request()->user();
        $employee = $user->employee;

        // Jenis cuti dropdown
        $jenisCuti = RefJenisCuti::all();

        // Cek saldo cuti tahunan (opsional untuk ditampilkan di UI)
        $saldoTahunan = null;
        if ($employee) {
            $saldoTahunan = LeaveBalance::where('employee_id', $employee->id)
                ->where('tahun', now()->year)
                ->first();
        }

        return view('admin.cuti.form-pengajuan', compact('employee', 'jenisCuti', 'saldoTahunan'));
    }

    /**
     * Menampilkan detail satu pengajuan cuti.
     * Pegawai tanpa hak memantau hanya boleh membuka pengajuan miliknya sendiri (cegah akses lintas pegawai).
     */
    public function show($id, LeaveApprovalService $approvals)
    {
        $user = request()->user();

        $cuti = LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'approvals.approver', 'steps.approver'])
            ->findOrFail($id);

        // Tombol setujui/tunda hanya muncul bila pengguna ini adalah approver tahap yang sedang menunggu;
        // otorisasi sebenarnya tetap ditegakkan ulang di service saat aksi dijalankan.
        $stage = $approvals->pendingStage($cuti);
        $canAct = $stage !== null
            && $approvals->approverEmployeeIdForStage($cuti, $stage) === $user->employee_id;

        if (! $user->hasPermission('cuti.read_all') && $cuti->employee_id !== $user->employee_id && ! $canAct) {
            abort(403);
        }

        return view('admin.cuti.show', [
            'cuti' => $cuti,
            'canAct' => $canAct,
            'canResubmit' => $cuti->status === 'perlu_perubahan' && $cuti->employee_id === $user->employee_id,
            'activeStep' => $stage === null ? null : $cuti->steps->firstWhere('step_order', $stage),
        ]);
    }

    /**
     * Menyimpan pengajuan cuti baru.
     * Validasi domain (atasan langsung, jenis khusus PNS, kecukupan saldo) ditegakkan di FormRequest;
     * orkestrasi penyimpanan, notifikasi, dan audit didelegasikan ke Action.
     */
    public function store(StoreLeaveRequestRequest $request, SubmitLeaveRequestAction $action)
    {
        $employee = $request->user()->employee;

        $action->execute($employee, $request->validated(), $request);

        return redirect()->route('cuti')
            ->with('success', 'Pengajuan cuti berhasil dikirim dan menunggu persetujuan atasan langsung.');
    }

    /** Mengirim ulang pengajuan perlu perubahan dengan snapshot approval yang sama. */
    public function resubmit(ResubmitLeaveRequestRequest $request, LeaveRequest $leaveRequest, ResubmitLeaveRequestAction $action)
    {
        $action->execute($leaveRequest, $request->validated(), $request);

        return redirect()->route('cuti.show', $leaveRequest)
            ->with('success', 'Perubahan pengajuan cuti berhasil dikirim ulang.');
    }

    /**
     * Menampilkan daftar pengajuan cuti yang menunggu tindakan approver yang sedang login.
     * Daftar dibatasi pada pengajuan yang approver tahap menunggunya adalah pegawai milik pengguna ini,
     * sehingga seorang approver hanya melihat pengajuan yang memang menjadi tanggung jawabnya.
     */
    public function approval(LeaveApprovalService $approvals)
    {
        $employeeId = request()->user()->employee_id;

        // Hanya pengajuan snapshot yang masih aktif/ditangguhkan yang relevan untuk antrean approver.
        $kandidat = LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'steps'])
            ->whereIn('status', ['menunggu_approval', 'ditangguhkan'])
            ->whereHas('steps', fn ($query) => $query
                ->where('status', 'active')
                ->where('approver_employee_id', $employeeId))
            ->latest()
            ->get();

        // Penyaringan approver bersifat person-based: cocokkan approver tahap menunggu dengan pegawai pengguna ini.
        $pending = $kandidat->filter(function (LeaveRequest $cuti) use ($approvals, $employeeId): bool {
            $stage = $approvals->pendingStage($cuti);

            return $stage !== null
                && $approvals->approverEmployeeIdForStage($cuti, $stage) === $employeeId;
        })->values();

        return view('admin.cuti.approval', ['pending' => $pending]);
    }

    /**
     * Menyetujui satu pengajuan cuti pada tahap yang sedang menunggu.
     * Kelayakan approver per-tahap dan transisi status ditegakkan di Action/Service, bukan di controller.
     */
    public function approve(ApproveLeaveRequest $request, $id, ApproveLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        // Pengguna tanpa data pegawai (mis. akun sistem) tidak dapat menjadi approver; tolak dengan jelas.
        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat menyetujui cuti.');

        $action->execute($leaveRequest, $actor, $request->validated()['komentar'] ?? null, $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti berhasil disetujui.');
    }

    /**
     * Menunda satu pengajuan cuti pada tahap yang sedang menunggu; alasan penundaan wajib diisi.
     */
    public function postpone(PostponeLeaveRequest $request, $id, PostponeLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        // Pengguna tanpa data pegawai (mis. akun sistem) tidak dapat menjadi approver; tolak dengan jelas.
        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat menunda cuti.');

        $action->execute($leaveRequest, $actor, $request->validated()['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti ditunda dan pemohon telah diberi tahu.');
    }

    /** Meminta perubahan pengajuan cuti pada step aktif; catatan wajib menjadi dasar revisi pemohon. */
    public function requestChanges(ReviewLeaveDecisionRequest $request, $id, RequestChangesLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat meminta perubahan cuti.');

        $action->execute($leaveRequest, $actor, $request->validated()['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti dikembalikan untuk perbaikan.');
    }

    /** Menolak pengajuan cuti secara terminal pada step aktif. */
    public function reject(ReviewLeaveDecisionRequest $request, $id, RejectLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat menolak cuti.');

        $action->execute($leaveRequest, $actor, $request->validated()['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti tidak disetujui dan pemohon telah diberi tahu.');
    }
}
