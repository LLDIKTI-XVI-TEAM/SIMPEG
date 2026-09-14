<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Employees\PrepareEmployeeHistoryAttachmentDownloadAction;
use App\Actions\Employees\PrepareRbacEmployeeDetailAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\DocumentCategory;
use Illuminate\Support\Facades\Storage;

class RbacEmployeeController extends Controller
{
    private const HISTORY_ATTACHMENT_TYPES = ['rank', 'position', 'salary', 'appointment', 'education'];

    public function show(Employee $employee, PrepareRbacEmployeeDetailAction $action)
    {
        /** @var User $viewer */
        $viewer = request()->user();

        return view('rbac.pegawai.show', $action->execute($employee->id, $viewer));
    }

    public function downloadDocument(Employee $employee, string $document, PrepareDocumentDownloadAction $action)
    {
        $viewer = request()->user();
        $allowed = $viewer !== null && $viewer->getEffectiveRole() === 'pimpinan'
            ? DocumentCategory::visibleToPimpinanKeys()
            : DocumentCategory::keys();
        $download = $action->execute($document, $employee->id, $allowed, rejectAmbiguousMetadata: true);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadDisciplineAttachment(Employee $employee, string $history, PrepareEmployeeHistoryAttachmentDownloadAction $action)
    {
        $download = $action->execute($employee, 'discipline', $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadStatusAttachment(Employee $employee, string $history, PrepareEmployeeHistoryAttachmentDownloadAction $action)
    {
        $type = hash_equals($employee->id, $history) ? 'status-snapshot' : 'status';
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadHistoryAttachment(Employee $employee, string $type, string $history, PrepareEmployeeHistoryAttachmentDownloadAction $action)
    {
        abort_unless(in_array($type, self::HISTORY_ATTACHMENT_TYPES, true), 404);
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
