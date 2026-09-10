<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApplyEmployeeApprovalChainsAction;
use App\Actions\Cuti\LookupChainTargetsAction;
use App\Actions\Cuti\LookupEmployeesAction;
use App\Actions\Cuti\PreviewEmployeeApprovalChainsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ChainApproverLookupRequest;
use App\Http\Requests\Cuti\EmployeeApprovalChainBatchRequest;
use App\Http\Requests\Cuti\LookupChainTargetsRequest;
use Illuminate\Http\JsonResponse;

class EmployeeApprovalChainBatchController extends Controller
{
    /** Hasil penerapan memuat keputusan final server tanpa menyimpan data pegawai dalam cache browser. */
    public function apply(EmployeeApprovalChainBatchRequest $request, ApplyEmployeeApprovalChainsAction $action): JsonResponse
    {
        $result = $action->execute($request->user(), $request->validated(), $request->validated('preview_token'), $request);
        // Hanya hasil final yang sudah tersimpan diteruskan ke toast halaman tujuan, tanpa identitas pegawai.
        $request->session()->flash('cuti_batch_success_counts', $result['data']['counts']);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    /** Pratinjau dikembalikan tanpa cache karena memuat konfigurasi pegawai yang scoped. */
    public function preview(EmployeeApprovalChainBatchRequest $request, PreviewEmployeeApprovalChainsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), $request->validated()))->header('Cache-Control', 'private, no-store');
    }

    /** Daftar target dibatasi service berdasarkan identitas asli pemanggil. */
    public function targets(LookupChainTargetsRequest $request, LookupChainTargetsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), $request->validated()))->header('Cache-Control', 'private, no-store');
    }

    /** Referensi approver memuat identitas minimum tanpa akses detail profil. */
    public function approvers(ChainApproverLookupRequest $request, LookupEmployeesAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated('q'))])->header('Cache-Control', 'private, no-store');
    }
}
