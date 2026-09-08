<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\ListAuditLogPageAction;
use App\Actions\Audit\ShowAuditLogAction;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request, ListAuditLogPageAction $action): View
    {
        return view('admin.audit.index', $action->execute($request->query(), $request->user()));
    }

    public function show(string $id, Request $request, ShowAuditLogAction $action): View
    {
        $log = $action->execute($id, $request->user());

        return view('admin.audit.show', compact('log'));
    }
}
