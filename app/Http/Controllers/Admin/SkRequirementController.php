<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\UpdateSkRequirementMatrixAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SkRequirement\UpdateSkRequirementMatrixRequest;
use App\Services\Documents\SkRequirementMatrixVersionService;
use Illuminate\Http\JsonResponse;

class SkRequirementController extends Controller
{
    public function update(
        UpdateSkRequirementMatrixRequest $request,
        UpdateSkRequirementMatrixAction $action,
        SkRequirementMatrixVersionService $matrixVersion,
    ): JsonResponse {
        /** @var array<string, list<string>> $matrix */
        $matrix = $request->validated('matrix');
        $saved = $action->execute($matrix, $request);

        return response()->json([
            'message' => 'Matriks SK wajib per jenis pegawai berhasil diperbarui.',
            'matrix' => $saved,
            'version' => $matrixVersion->current(),
        ]);
    }
}
