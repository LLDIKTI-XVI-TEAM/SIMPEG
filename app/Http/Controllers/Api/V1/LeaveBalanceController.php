<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cuti\ShowMyLeaveBalanceAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /**
     * API: Mengembalikan saldo dan riwayat cuti milik pengguna login.
     * `sisa` mempertahankan summary tercatat, sedangkan `sisa_efektif` menyatakan
     * hak yang dapat digunakan setelah Rule 5 dan alokasi aktif diterapkan.
     */
    public function showMyBalance(Request $request, ShowMyLeaveBalanceAction $action): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'message' => 'Akun pengguna belum ter-mapping ke data pegawai.',
            ], 404);
        }

        $tahun = (int) $request->query('tahun', now()->year);

        return response()->json($action->forApi($employee, $tahun));
    }
}
