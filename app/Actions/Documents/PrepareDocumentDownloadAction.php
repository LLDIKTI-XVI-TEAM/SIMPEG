<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class PrepareDocumentDownloadAction
{
    public function execute(string $id): array
    {
        $document = Document::findOrFail($id);

        if (! Storage::disk('public')->exists($document->file_path)) {
            abort(404);
        }

        return [
            'path' => $document->file_path,
            'filename' => basename($document->file_path),
        ];
    }
}
