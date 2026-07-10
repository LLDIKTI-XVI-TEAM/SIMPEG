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
        return response()->json([
            'message' => 'Daftar dokumen berhasil diambil.',
            'documents' => $action->execute($request->validated()),
        ]);
    }
}
