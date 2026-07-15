<?php

namespace App\Http\Controllers;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PimpinanEwsController extends Controller
{
    public function index(Request $request, ListActiveEwsAlertsAction $action)
    {
        $filterEvent = (string) $request->query('event', '');
        $filterStatus = (string) $request->query('status', '');
        $data = $action->execute($filterEvent, $filterStatus);

        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $offset = ($page - 1) * $perPage;

        $alerts = new LengthAwarePaginator(
            array_slice($data['alerts'], $offset, $perPage),
            count($data['alerts']),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pimpinan.ews.index', [
            'alerts' => $alerts,
            'filterEvent' => $filterEvent,
            'filterStatus' => $filterStatus,
            'typeLabels' => $data['type_labels'],
            'followupStatusLabels' => $data['followup_status_labels'],
        ]);
    }
}
