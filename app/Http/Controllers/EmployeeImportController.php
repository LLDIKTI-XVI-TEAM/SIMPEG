<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ImportEmployeesAction;
use App\Http\Requests\ImportEmployeesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EmployeeImportController extends Controller
{
    public function store(ImportEmployeesRequest $request, ImportEmployeesAction $action): JsonResponse|RedirectResponse
    {
        $summary = $action->execute($request);

        if ($request->expectsJson()) {
            return response()->json($summary, $summary['failed'] > 0 ? 422 : 200);
        }

        if ($summary['failed'] > 0) {
            return back()
                ->withErrors(['file' => $summary['message']])
                ->with('import_summary', $summary);
        }

        return back()->with('import_summary', $summary);
    }
}
