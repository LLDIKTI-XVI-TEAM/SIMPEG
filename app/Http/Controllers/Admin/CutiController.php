<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveLeaveRequest;
use App\Http\Requests\PostponeLeaveRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\LeaveApprovalService;
use Illuminate\Http\Request;

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

        $balances = $balancesQuery->get();
        $totalPegawai = Employee::where('status_aktif', 'Aktif')->count();
        $cutiTerpakai = $balances->sum('terpakai');
        $sisaSaldo = $balances->sum('sisa');
        $saldoKritis = $balances->where('sisa', '<=', 3)->count();

        $summary = [
            ['label' => 'Total Pegawai', 'value' => $totalPegawai, 'caption' => 'Pegawai aktif', 'tone' => 'primary'],
            ['label' => 'Cuti Terpakai', 'value' => $cutiTerpakai, 'caption' => 'Hari kerja tahun ini', 'tone' => 'info'],
            ['label' => 'Sisa Saldo', 'value' => $sisaSaldo, 'caption' => 'Akumulasi hari', 'tone' => 'success'],
            ['label' => 'Saldo Kritis', 'value' => $saldoKritis, 'caption' => 'Sisa <= 3 hari', 'tone' => 'danger'],
        ];

        $leaveBalances = $balances->map(function ($b) {
            $status = 'Aman';
            if ($b->sisa <= 3) {
                $status = 'Kritis';
            } elseif ($b->sisa <= 6) {
                $status = 'Perhatian';
            }

            return [
                'nama' => $b->employee?->nama_lengkap ?? '-',
                'nip' => $b->employee?->nip ?? '-',
                'unit' => $b->employee?->jabatan_terakhir ?? '-',
                'jatah' => $b->jatah_awal,
                'carry' => $b->carry_over,
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

        $usageRows = (clone $requestsQuery)->take(20)->get()->map(function ($r) {
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

        $statsQuery = LeaveRequest::where('status', 'Disetujui')->with('jenisCuti');
        if ($unit) {
            $statsQuery->whereHas('employee', function ($q) use ($unit) {
                $q->where('jabatan_terakhir', $unit);
            });
        }
        if ($pegawaiId) {
            $statsQuery->where('employee_id', $pegawaiId);
        }
        if ($periode) {
            if (is_numeric($periode)) {
                $statsQuery->whereYear('tanggal_mulai', $periode);
            } else {
                $parts = explode(' ', $periode);
                if (count($parts) === 2 && isset($months[$parts[0]])) {
                    $statsQuery->whereMonth('tanggal_mulai', $months[$parts[0]])->whereYear('tanggal_mulai', $parts[1]);
                }
            }
        }

        $stats = $statsQuery->get()
            ->groupBy('jenisCuti.nama')
            ->map(function ($group) {
                return $group->sum('jumlah_hari_kerja');
            });

        $totalDays = $stats->sum() ?: 1;
        $jenisStats = [];
        $tones = ['primary', 'info', 'secondary', 'muted'];
        $i = 0;
        foreach ($stats as $name => $days) {
            $jenisStats[] = [
                'label' => $name ?? 'Lainnya',
                'hari' => $days,
                'percent' => round(($days / $totalDays) * 100),
                'tone' => $tones[$i % 4],
            ];
            $i++;
        }

        // Data Dropdown Filter
        $optUnits = Employee::select('jabatan_terakhir')->whereNotNull('jabatan_terakhir')->distinct()->pluck('jabatan_terakhir');
        $optPegawais = Employee::where('status_aktif', 'Aktif')->get(['id', 'nama_lengkap', 'nip']);
        $optJenisCutis = RefJenisCuti::all();
        $optPeriodes = ['Semua Periode', 'Juni 2026', 'Mei 2026', 'April 2026', '2026', '2025'];

        return view('admin.cuti.rekap', compact(
            'summary', 'leaveBalances', 'usageRows', 'jenisStats',
            'optUnits', 'optPegawais', 'optJenisCutis', 'optPeriodes',
            'periode', 'unit', 'pegawaiId', 'jenisId'
        ));
    }

    /**
     * Menampilkan daftar pengajuan cuti.
     * Pegawai biasa hanya melihat pengajuannya sendiri; peran dengan hak memantau melihat seluruh pengajuan.
     */
    public function index()
    {
        $user = request()->user();

        $query = LeaveRequest::query()
            ->with(['employee', 'jenisCuti'])
            ->latest();

        // Pemantau (mis. admin kepegawaian/pimpinan) boleh melihat semua; selain itu dibatasi milik sendiri.
        if (! $user->hasPermission('cuti.read_all')) {
            $query->where('employee_id', $user->employee_id);
        }

        $riwayatCuti = $query->get();

        return view('admin.cuti.index', compact('riwayatCuti'));
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
            ->with(['employee', 'jenisCuti', 'approvals.approver'])
            ->findOrFail($id);

        if (! $user->hasPermission('cuti.read_all') && $cuti->employee_id !== $user->employee_id) {
            abort(403);
        }

        // Tombol setujui/tunda hanya muncul bila pengguna ini adalah approver tahap yang sedang menunggu;
        // otorisasi sebenarnya tetap ditegakkan ulang di service saat aksi dijalankan.
        $stage = $approvals->pendingStage($cuti);
        $canAct = $stage !== null
            && $approvals->approverEmployeeIdForStage($cuti, $stage) === $user->employee_id;

        return view('admin.cuti.show', [
            'cuti' => $cuti,
            'canAct' => $canAct,
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

    /**
     * Menampilkan daftar pengajuan cuti yang menunggu tindakan approver yang sedang login.
     * Daftar dibatasi pada pengajuan yang approver tahap menunggunya adalah pegawai milik pengguna ini,
     * sehingga seorang approver hanya melihat pengajuan yang memang menjadi tanggung jawabnya.
     */
    public function approval(LeaveApprovalService $approvals)
    {
        $employeeId = request()->user()->employee_id;

        // Hanya pengajuan berstatus menunggu/ditunda yang relevan untuk antrean approver.
        $kandidat = LeaveRequest::query()
            ->with(['employee', 'jenisCuti'])
            ->whereIn('status', [
                'Menunggu Atasan Langsung',
                'Menunggu Verifikator',
                'Menunggu Pimpinan',
                'Ditunda',
            ])
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
}
