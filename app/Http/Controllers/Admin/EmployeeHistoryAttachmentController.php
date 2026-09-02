<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\PrepareEmployeeHistoryAttachmentDownloadAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeHistoryAttachmentController extends Controller
{
    public function __invoke(
        Employee $employee,
        string $type,
        string $history,
        PrepareEmployeeHistoryAttachmentDownloadAction $action,
    ): StreamedResponse {
        $user = request()->user();
        // Granular permission gate per type + dokumen_sk.read sudah di middleware, tapi cek histories/discipline spesifik di sini
        if (in_array($type, ['rank', 'position', 'salary', 'appointment', 'education', 'status', 'status-snapshot'], true)) {
            abort_unless($user && ($user->hasPermission('employee_histories.read') || $user->getEffectiveRole() === 'super_admin'), 403);
        }
        if ($type === 'discipline') {
            abort_unless($user && ($user->hasPermission('discipline_records.read') || $user->getEffectiveRole() === 'super_admin'), 403);
        }
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
