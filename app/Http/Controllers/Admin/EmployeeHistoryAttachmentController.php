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
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
