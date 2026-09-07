<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ShowEmployeeStatisticsPageAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeStatisticsController extends Controller
{
    public function index(Request $request, ShowEmployeeStatisticsPageAction $action): View
    {
        $actor = $request->user();

        return view(
            'admin.reporting.employee-statistics',
            $action->execute($actor instanceof User ? $actor : null),
        );
    }
}
