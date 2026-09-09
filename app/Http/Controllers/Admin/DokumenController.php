<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\ListDocumentsPageAction;
use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Documents\ShowDocumentPageAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Employees\KepalaBagianScopeService;
use App\Support\Documents\DocumentAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Arsip dokumen terpusat bersifat baca-saja: seluruh aksi unggah/edit/hapus
 * dilakukan dari tab Dokumen & SK pada halaman detail pegawai melalui API pegawai.
 *
 * Lapisan privasi (K-privasi, terpisah dari RBAC aksi): arsip memuat dokumen
 * sensitif lintas pegawai (mis. ktp_kk) — baca/unduh hanya untuk pengelola data
 * kepegawaian (DocumentAuthorization::canViewArchive), meski permission
 * employees.read diberikan ke role lain lewat RBAC matrix.
 */
class DokumenController extends Controller
{
    public function index(Request $request, ListDocumentsPageAction $action)
    {
        abort_unless(DocumentAuthorization::canBrowseArchive($request->user()), 403, 'Arsip dokumen terpusat hanya tersedia untuk pengelola data kepegawaian.');

        return view('admin.dokumen.index', $action->execute($request->user()));
    }

    public function show(Request $request, string $id, ShowDocumentPageAction $action)
    {
        $user = $request->user();
        abort_unless(DocumentAuthorization::canBrowseArchive($user), 403, 'Arsip dokumen terpusat hanya tersedia untuk pengelola data kepegawaian.');

        $payload = $action->execute($id);
        if ($user !== null && $user->getEffectiveRole() === 'kepala_bagian') {
            $documentEmployeeId = Document::query()->whereKey($id)->value('employee_id');
            abort_unless(
                is_string($documentEmployeeId)
                    && app(KepalaBagianScopeService::class)->hasDirectReport($user, $documentEmployeeId),
                403,
                'Dokumen hanya tersedia untuk bawahan langsung Anda.'
            );
        }
        if ($user !== null && $user->getEffectiveRole() === 'pegawai') {
            $documentEmployeeId = Document::query()->whereKey($id)->value('employee_id');
            $ownId = (string) ($user->employee_id ?? '');
            abort_unless(
                $ownId !== ''
                    && is_string($documentEmployeeId)
                    && hash_equals($ownId, $documentEmployeeId),
                403,
                'Dokumen hanya tersedia untuk data Anda sendiri.'
            );
        }

        return view('admin.dokumen.show', $payload);
    }

    public function store()
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Unggah, ubah, dan hapus dokumen dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }

    public function update(string $id)
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Unggah, ubah, dan hapus dokumen dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }

    public function download(Request $request, string $id, PrepareDocumentDownloadAction $action)
    {
        $user = $request->user();
        abort_unless(DocumentAuthorization::canBrowseArchive($user), 403, 'Arsip dokumen terpusat hanya tersedia untuk pengelola data kepegawaian.');

        if ($user !== null && $user->getEffectiveRole() === 'kepala_bagian') {
            $documentEmployeeId = Document::query()->whereKey($id)->value('employee_id');
            abort_unless(
                is_string($documentEmployeeId)
                    && app(KepalaBagianScopeService::class)->hasDirectReport($user, $documentEmployeeId),
                403,
                'Dokumen hanya tersedia untuk bawahan langsung Anda.'
            );
        }
        if ($user !== null && $user->getEffectiveRole() === 'pegawai') {
            $documentEmployeeId = Document::query()->whereKey($id)->value('employee_id');
            $ownId = (string) ($user->employee_id ?? '');
            abort_unless(
                $ownId !== ''
                    && is_string($documentEmployeeId)
                    && hash_equals($ownId, $documentEmployeeId),
                403,
                'Dokumen hanya tersedia untuk data Anda sendiri.'
            );
        }

        $download = $action->execute($id);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function checkImpact(string $id)
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Penghapusan berkas dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }

    public function destroy(string $id)
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Penghapusan berkas dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }
}
