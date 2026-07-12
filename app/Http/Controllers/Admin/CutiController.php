<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\PrepareLeaveRequestFormAction;
use App\Actions\Cuti\RejectLeaveAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\ShowCutiRekapAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApproveLeaveRequest;
use App\Http\Requests\Cuti\PostponeLeaveRequest;
use App\Http\Requests\Cuti\ResubmitLeaveRequestRequest;
use App\Http\Requests\Cuti\ReviewLeaveDecisionRequest;
use App\Http\Requests\Cuti\StoreLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\LeaveApprovalService;
use Illuminate\Http\Request;

class CutiController extends Controller
{
    public function rekap(Request $request, ShowCutiRekapAction $action)
    {
        return view('admin.cuti.rekap', $action->execute($request->query()));
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
     * Penyusunan data form (saldo ledger, jenis cuti, kesiapan chain) didelegasikan ke Action agar controller tetap tipis.
     */
    public function create(PrepareLeaveRequestFormAction $action)
    {
        $employee = request()->user()?->employee;

        // Akun tanpa data pegawai tidak boleh mengajukan cuti; tolak di backend, bukan hanya menyembunyikan menu.
        abort_if($employee === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat mengajukan cuti.');

        return view('admin.cuti.form-pengajuan', $action->execute($employee));
    }

    /**
     * Menampilkan detail satu pengajuan cuti.
     * Pegawai tanpa hak memantau hanya boleh membuka pengajuan miliknya sendiri (cegah akses lintas pegawai).
     */
    public function show($id, LeaveApprovalService $approvals)
    {
        $user = request()->user();

        $cuti = LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'proof', 'approvals.approver', 'steps.approver'])
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
