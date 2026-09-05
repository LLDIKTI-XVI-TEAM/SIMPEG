<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\BuildCutiDetailAction;
use App\Actions\Cuti\DeclineLeaveAction;
use App\Actions\Cuti\DownloadLeaveAttachmentAction;
use App\Actions\Cuti\DownloadOfficialLeavePdfAction;
use App\Actions\Cuti\ListLeaveRequestsAction;
use App\Actions\Cuti\ListPendingLeaveApprovalsAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\PrepareLeaveRequestFormAction;
use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Actions\Cuti\ResubmitLeaveRequestAction;
use App\Actions\Cuti\ShowCutiRekapAction;
use App\Actions\Cuti\SubmitLeaveRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApproveLeaveRequest;
use App\Http\Requests\Cuti\ListCutiRekapRequest;
use App\Http\Requests\Cuti\PostponeLeaveRequest;
use App\Http\Requests\Cuti\RecordDutyPostponementRequest;
use App\Http\Requests\Cuti\ResubmitLeaveRequestRequest;
use App\Http\Requests\Cuti\ReviewLeaveDecisionRequest;
use App\Http\Requests\Cuti\StoreLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CutiController extends Controller
{
    /** Menyajikan formulir resmi setelah otorisasi rekam ditegakkan Action. */
    public function formulirPdf(LeaveRequest $leaveRequest, DownloadOfficialLeavePdfAction $action): Response
    {
        return $action->execute($leaveRequest, request()->user());
    }

    public function rekap(ListCutiRekapRequest $request, ShowCutiRekapAction $action): View
    {
        /** @var User $actor */
        $actor = $request->user();

        return view('admin.cuti.rekap', $action->execute($request->validated(), $actor));
    }

    /**
     * Menampilkan daftar pengajuan cuti.
     * Pegawai biasa hanya melihat pengajuannya sendiri; peran dengan hak memantau melihat seluruh pengajuan.
     */
    public function index(Request $request, ListLeaveRequestsAction $action)
    {
        $user = $request->user();

        // Pengguna tanpa pegawai terkait tetap boleh melihat daftar bila punya cuti.read_all;
        // scope milik-sendiri di Action memakai employee_id (null aman untuk pemantau ber-read_all).
        return view('admin.cuti.index', $action->execute($user, $request));
    }

    /**
     * Menampilkan form pengajuan cuti baru.
     * Penyusunan data form (saldo ledger, jenis cuti, kesiapan chain) didelegasikan ke Action agar controller tetap tipis.
     */
    public function create(Request $request, PrepareLeaveRequestFormAction $action)
    {
        $employee = $request->user()?->employee;

        // Akun tanpa data pegawai tidak boleh mengajukan cuti; tolak di backend, bukan hanya menyembunyikan menu.
        abort_if($employee === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat mengajukan cuti.');

        $selectedLeaveTypeId = $request->old('jenis_cuti_id');
        $selectedLeaveCaseId = $request->old('leave_request_case_id');

        return view('admin.cuti.form-pengajuan', $action->execute(
            $employee,
            is_string($selectedLeaveTypeId) ? $selectedLeaveTypeId : null,
            is_string($selectedLeaveCaseId) ? $selectedLeaveCaseId : null,
        ));
    }

    /** Menampilkan detail cuti dari konteks baca yang telah diotorisasi Action. */
    public function show(LeaveRequest $id, Request $request, BuildCutiDetailAction $action)
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.cuti.show', $action->execute($id, $user));
    }

    /** Unduhan lampiran pemohon/read-all didelegasikan ke Action dengan guard kepemilikan. */
    public function downloadAttachment(LeaveRequest $leaveRequest, Request $request, DownloadLeaveAttachmentAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $action->forOwnerOrReadAll($leaveRequest, $user);
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
    public function approval(Request $request, ListPendingLeaveApprovalsAction $action)
    {
        $employeeId = $request->user()->employee_id;
        $perPage = (int) $request->query('per_page', 10);

        $pending = $action->execute($employeeId, $perPage);

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

        $payload = $request->validated();
        $action->execute($leaveRequest, $actor, $payload['active_step_id'], $payload['komentar'] ?? null, $request);

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

        $payload = $request->validated();
        $action->execute($leaveRequest, $actor, $payload['active_step_id'], $payload['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti ditunda dan pemohon telah diberi tahu.');
    }

    /** Meminta perubahan pengajuan cuti pada step aktif; catatan wajib menjadi dasar revisi pemohon. */
    public function requestChanges(ReviewLeaveDecisionRequest $request, $id, RequestChangesLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat meminta perubahan cuti.');

        $payload = $request->validated();
        $action->execute($leaveRequest, $actor, $payload['active_step_id'], $payload['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti dikembalikan untuk perbaikan.');
    }

    /** Menutup pengajuan sebagai Tidak Disetujui pada step aktif. */
    public function decline(ReviewLeaveDecisionRequest $request, $id, DeclineLeaveAction $action)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $actor = $request->user()->employee;

        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat memutuskan cuti.');

        $payload = $request->validated();
        $action->execute($leaveRequest, $actor, $payload['active_step_id'], $payload['komentar'], $request);

        return redirect()->route('cuti.approval')
            ->with('success', 'Pengajuan cuti tidak disetujui dan pemohon telah diberi tahu.');
    }

    /** Mencatat terminal penangguhan tugas dinas melalui Action yang menegakkan snapshot approver. */
    public function recordDutyPostponement(
        RecordDutyPostponementRequest $request,
        LeaveRequest $leave,
        RecordDutyPostponementAction $action,
    ) {
        $user = $request->user();
        $actor = $user?->employee;
        abort_if($user === null || $actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat menangguhkan cuti.');

        $payload = $request->validated();
        $action->execute($leave, $actor, $user, $payload['active_step_id'], $payload['alasan']);

        return redirect()->route('cuti.approval')
            ->with('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');
    }
}
