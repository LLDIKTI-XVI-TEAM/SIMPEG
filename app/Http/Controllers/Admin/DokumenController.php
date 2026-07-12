<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\ListDocumentsPageAction;
use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Documents\ShowDocumentPageAction;
use App\Actions\Documents\StoreDocumentAction;
use App\Actions\Documents\UpdateDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    public function store(StoreDocumentRequest $request, StoreDocumentAction $action)
    {
        $document = $action->execute($request->validated(), $request->file('berkas'));

        return redirect()->route('dokumen')
            ->with('success', 'Dokumen "'.$document->nama_dokumen.'" berhasil diunggah.')
            ->with('document_data_changed', true);
    }

    public function update(string $id, UpdateDocumentRequest $request, UpdateDocumentAction $action)
    {
        $document = Document::findOrFail($id);
        
        $document = $action->execute($document, $request->validated(), $request->file('berkas'));

        return redirect()->route('dokumen')
            ->with('success', 'Dokumen "'.$document->nama_dokumen.'" berhasil diperbarui.')
            ->with('document_data_changed', true);
    }

    public function download(string $id, PrepareDocumentDownloadAction $action)
    {
        $download = $action->execute($id);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename']);
    }

    /**
     * Cek dampak penghapusan dokumen (data riwayat yang terhubung).
     * Diakses via AJAX sebelum konfirmasi hapus.
     */
    public function checkImpact(string $id, DeleteDocumentAction $action)
    {
        $document = Document::findOrFail($id);
        $result = $action->checkImpact($document);

        return response()->json([
            'nomor_dokumen' => $document->nomor_dokumen,
            'nama_dokumen' => $document->nama_dokumen,
            ...$result,
        ]);
    }

    /**
     * Hapus dokumen dan (opsional) semua riwayat terkait.
     * Hanya Super Admin.
     */
    public function destroy(string $id, Request $request, DeleteDocumentAction $action)
    {
        $document = Document::findOrFail($id);
        $forceDeleteRelated = (bool) $request->input('force_delete_related', false);

        $action->execute($document, $forceDeleteRelated);

        return redirect()->route('dokumen')
            ->with('success', 'Dokumen berhasil dihapus.')
            ->with('document_data_changed', true);
    }
}
