<?php

namespace App\Http\Controllers;

use App\Actions\Audit\ListAuditLogsAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, ListAuditLogsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->query()));
    }
}
