<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\DeleteKepalaLembagaSupportingDocumentAction;
use App\Actions\Cuti\ListKepalaLembagaSupportingDocumentsAction;
use App\Actions\Cuti\PrepareKepalaLembagaSupportingDocumentResponseAction;
use App\Actions\Cuti\StoreKepalaLembagaSupportingDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\StoreKepalaLembagaSupportingDocumentRequest;
use App\Models\Employee;
use App\Models\KepalaLembagaSupportingDocument;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adapter HTTP untuk pengelolaan dokumen pendukung privat Kepala Lembaga.
 */
class KepalaLembagaSupportingDocumentController extends Controller
{
    public function index(Request $request, ListKepalaLembagaSupportingDocumentsAction $action): View
    {
        $kepalaLembaga = Employee::query()
            ->where('is_kepala_lembaga', true)
            ->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap', 'is_kepala_lembaga']);

        $requestedId = $request->query('employee');
        $selected = is_string($requestedId) && Str::isUuid($requestedId)
            ? $kepalaLembaga->firstWhere('id', $requestedId)
            : null;
        $selected ??= $kepalaLembaga->first();

        $documents = $selected instanceof Employee
            ? $action->execute($selected, (int) $request->integer('per_page', 10))
            : new LengthAwarePaginator([], 0, 10);

        return view('admin.cuti.dokumen-kepala-lembaga', [
            'kepalaLembaga' => $kepalaLembaga,
            'selected' => $selected,
            'documents' => $documents,
        ]);
    }

    public function store(
        StoreKepalaLembagaSupportingDocumentRequest $request,
        Employee $employee,
        StoreKepalaLembagaSupportingDocumentAction $action,
    ): RedirectResponse {
        if (! $employee->is_kepala_lembaga) {
            abort(404);
        }

        $file = $request->file('berkas');
        $actor = $request->user();

        if (! $file instanceof UploadedFile || ! $actor instanceof User) {
            abort(422);
        }

        $action->execute($employee, $file, $actor);

        return redirect()
            ->route('cuti.dokumen-kepala-lembaga.index', ['employee' => $employee->id])
            ->with('success', 'Dokumen pendukung berhasil diunggah.');
    }

    public function view(
        KepalaLembagaSupportingDocument $document,
        PrepareKepalaLembagaSupportingDocumentResponseAction $action,
    ): StreamedResponse {
        return $action->execute($document, disposition: 'inline');
    }

    public function download(
        KepalaLembagaSupportingDocument $document,
        PrepareKepalaLembagaSupportingDocumentResponseAction $action,
    ): StreamedResponse {
        return $action->execute($document, disposition: 'attachment');
    }

    public function destroy(
        Request $request,
        KepalaLembagaSupportingDocument $document,
        DeleteKepalaLembagaSupportingDocumentAction $action,
    ): RedirectResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $employeeId = $document->employee_id;
        $action->execute($document, $actor);

        return redirect()
            ->route('cuti.dokumen-kepala-lembaga.index', ['employee' => $employeeId])
            ->with('success', 'Dokumen pendukung berhasil dihapus.');
    }
}
