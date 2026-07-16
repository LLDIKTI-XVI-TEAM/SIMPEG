<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\ListDocumentsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ListDocumentsRequest;
use Illuminate\Http\JsonResponse;

class DokumenApiController extends Controller
{
    public function index(ListDocumentsRequest $request, ListDocumentsAction $action): JsonResponse
    {
        return response()
            ->json([
                'message' => 'Daftar dokumen berhasil diambil.',
                'documents' => $action->execute($request->validated()),
                'file_status_checked_at' => now()->toIso8601String(),
            ])
            // Status file berasal dari filesystem, sehingga respons daftar tidak boleh
            // disajikan dari cache HTTP ketika pengguna meminta data terbaru.
            ->header('Cache-Control', 'no-store, private, max-age=0, must-revalidate');
    }
}
