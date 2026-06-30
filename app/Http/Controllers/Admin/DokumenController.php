<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\ListDocumentsPageAction;
use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Documents\ShowDocumentPageAction;
use App\Actions\Documents\StoreDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Models\Document;
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
            ->with('success', 'Dokumen "'.$document->nama_dokumen.'" berhasil diunggah.');
    }

    public function download(string $id, PrepareDocumentDownloadAction $action)
    {
        $download = $action->execute($id);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename']);
    }
}
