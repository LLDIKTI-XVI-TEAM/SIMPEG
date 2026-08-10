<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\ListAuditLogPageAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit\AuditLogViewPayload;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request, ListAuditLogPageAction $action): View
    {
        return view('admin.audit.index', $action->execute($request->query()));
    }

    public function show(string $id): View
    {
        $log = AuditLogViewPayload::forView(AuditLog::query()->findOrFail($id));

        return view('admin.audit.show', compact('log'));
    }
}
