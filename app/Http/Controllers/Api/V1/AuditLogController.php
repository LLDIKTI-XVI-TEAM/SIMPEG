<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\ListAuditLogsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, ListAuditLogsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->query()));
    }
}
