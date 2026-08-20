<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\UpdateSkRequirementMatrixAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SkRequirement\UpdateSkRequirementMatrixRequest;
use Illuminate\Http\JsonResponse;

/**
 * Konfigurasi "SK wajib per jenis pegawai".
 *
 * Editor matriks ditampilkan sebagai modal pada halaman data pegawai (icon gear
 * khusus super admin); endpoint ini hanya dipakai untuk menyimpan perubahan.
 */
class SkRequirementController extends Controller
{
    public function update(
        UpdateSkRequirementMatrixRequest $request,
        UpdateSkRequirementMatrixAction $action,
    ): JsonResponse {
        $action->execute($request);

        return response()->json([
            'message' => 'Matriks SK wajib per jenis pegawai berhasil diperbarui.',
        ]);
    }
}
