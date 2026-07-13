<?php

namespace App\Http\Controllers;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use Illuminate\Http\Request;

class PimpinanEwsController extends Controller
{
    public function index(Request $request, ListActiveEwsAlertsAction $action)
    {
        $filterEvent = (string) $request->query('event', '');
        $filterStatus = (string) $request->query('status', '');
        $data = $action->execute($filterEvent, $filterStatus);

        return view('pimpinan.ews.index', [
            'alerts' => $data['alerts'],
            'filterEvent' => $filterEvent,
            'filterStatus' => $filterStatus,
            'typeLabels' => $data['type_labels'],
            'followupStatusLabels' => $data['followup_status_labels'],
        ]);
    }
}
