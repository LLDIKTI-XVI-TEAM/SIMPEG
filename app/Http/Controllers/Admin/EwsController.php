<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Actions\Ews\UpdateEwsAlertFollowupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ews\AdminEwsFilterRequest;
use App\Http\Requests\Ews\UpdateEwsAlertFollowupRequest;
use App\Models\EwsAlert;
use App\Models\RefGolongan;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Http\Request;

class EwsController extends Controller
{
    /**
     * Menampilkan daftar EWS aktif dari alert database dengan filter dan eligibility.
     */
    public function index(
        AdminEwsFilterRequest $request,
        ListActiveEwsAlertsAction $action,
        EmployeeDashboardScopeService $employeeScope,
    ) {
        $validated = $request->validated();
        $filterSearch = trim((string) ($validated['search'] ?? ''));
        $filterEvent = (string) ($validated['event'] ?? '');
        $filterStatus = (string) ($validated['status'] ?? '');
        $perPage = (int) ($validated['per_page'] ?? 10);
        $actor = $request->user();
        $scope = $actor !== null && in_array($actor->getEffectiveRole(), ['kepala_bagian', 'pegawai'], true)
            ? $employeeScope->for($actor)
            : null;
        $data = $action->paginate($filterEvent, $filterStatus, $filterSearch, $perPage, employeeScope: $scope);

        return view('admin.ews.aktif', [
            'alerts' => $data['alerts'],
            'filterEvent' => $filterEvent,
            'filterStatus' => $filterStatus,
            'filterSearch' => $filterSearch,
            'summary' => $data['summary'],
            'typeLabels' => $data['type_labels'],
            'followupStatusLabels' => $data['followup_status_labels'],
            'golonganOptions' => RefGolongan::query()
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
            'title' => 'EWS Aktif',
        ]);
    }

    /**
     * Menampilkan EWS pribadi milik pegawai yang sedang login.
     */
    public function myAlerts(Request $request, ListActiveEwsAlertsAction $action)
    {
        $employeeId = $request->user()?->employee_id;

        abort_unless($employeeId, 404, 'Data pegawai untuk akun ini belum terhubung.');

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $data = $action->paginate(null, null, null, $perPage, (string) $employeeId);

        return view('admin.ews.saya', [
            'alerts' => $data['alerts'],
            'title' => 'EWS Saya',
        ]);
    }

    /**
     * Menandai alert EWS sebagai ditangani atau tidak perlu dengan catatan.
     */
    public function updateFollowup(
        UpdateEwsAlertFollowupRequest $request,
        EwsAlert $alert,
        UpdateEwsAlertFollowupAction $action,
    ) {
        $updated = $action->execute(
            $alert,
            (string) $request->validated('followup_status'),
            (string) $request->validated('handled_note'),
            $request,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Status tindak lanjut EWS berhasil diperbarui.',
                'id' => $updated->id,
                'followup_status' => $updated->followup_status,
            ]);
        }

        return back()->with('success', 'Status tindak lanjut EWS berhasil diperbarui.');
    }
}
