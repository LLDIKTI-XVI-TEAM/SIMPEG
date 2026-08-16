<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\ListDocumentsPageAction;
use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Documents\ShowDocumentPageAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * Arsip dokumen terpusat bersifat baca-saja (keputusan produk K-MTG-04):
 * seluruh aksi unggah/edit/hapus dilakukan dari tab Dokumen & SK pada
 * halaman detail pegawai melalui API pegawai.
 */
class DokumenController extends Controller
{
    public function index(ListDocumentsPageAction $action)
    {
        return view('admin.dokumen.index', $action->execute());
    }

    public function show(string $id, ShowDocumentPageAction $action)
    {
        return view('admin.dokumen.show', $action->execute($id));
    }

    public function store()
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Unggah, ubah, dan hapus dokumen dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }

    public function update(string $id)
    {
        abort(403, 'Arsip dokumen bersifat baca-saja. Unggah, ubah, dan hapus dokumen dilakukan dari halaman detail pegawai pada bagian Dokumen & SK.');
    }

    public function download(string $id, PrepareDocumentDownloadAction $action)
    {
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
