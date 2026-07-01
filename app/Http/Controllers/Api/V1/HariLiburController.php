<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\HariLibur\CreateHariLiburAction;
use App\Actions\HariLibur\DeleteHariLiburAction;
use App\Actions\HariLibur\ListHariLiburAction;
use App\Actions\HariLibur\UpdateHariLiburAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\HariLibur\StoreHariLiburRequest;
use App\Http\Requests\HariLibur\UpdateHariLiburRequest;
use App\Models\RefHariLibur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HariLiburController extends Controller
{
    public function index(Request $request, ListHariLiburAction $action): JsonResponse
    {
        $tahun = $request->filled('tahun') ? (int) $request->input('tahun') : null;

        return response()->json([
            'data' => $action->execute($tahun),
        ]);
    }

    public function store(StoreHariLiburRequest $request, CreateHariLiburAction $action): JsonResponse
    {
        $hariLibur = $action->execute($request->validated(), $request);

        return response()->json([
            'message' => 'Hari libur berhasil ditambahkan.',
            'data' => $hariLibur->toApiArray(),
        ], 201);
    }

    public function update(
        UpdateHariLiburRequest $request,
        RefHariLibur $hariLibur,
        UpdateHariLiburAction $action,
    ): JsonResponse {
        $hariLibur = $action->execute($hariLibur, $request->validated(), $request);

        return response()->json([
            'message' => 'Hari libur berhasil diperbarui.',
            'data' => $hariLibur->toApiArray(),
        ]);
    }

    public function destroy(Request $request, RefHariLibur $hariLibur, DeleteHariLiburAction $action): JsonResponse
    {
        $action->execute($hariLibur, $request);

        return response()->json([
            'message' => 'Hari libur berhasil dihapus.',
        ]);
    }
}
