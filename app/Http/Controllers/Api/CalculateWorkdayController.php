<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cuti\CalculateWorkdaysAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateWorkdaysRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Endpoint kalkulasi hari kerja untuk form pengajuan cuti (AJAX realtime).
 * Controller dijaga tipis: validasi di FormRequest, orkestrasi di Action, logika domain di service.
 */
class CalculateWorkdayController extends Controller
{
    public function __invoke(CalculateWorkdaysRequest $request, CalculateWorkdaysAction $action): JsonResponse
    {
        $start = Carbon::parse($request->validated('start'));
        $end = Carbon::parse($request->validated('end'));

        return response()->json([
            'data' => $action->execute($start, $end),
        ]);
    }
}
