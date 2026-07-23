<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PimpinanEwsController extends Controller
{
    public function index(Request $request, ListActiveEwsAlertsAction $action)
    {
        $filterSearch = trim((string) $request->query('search', ''));
        $filterEvent = (string) $request->query('event', '');
        $filterStatus = (string) $request->query('status', '');
        $data = $action->execute($filterEvent, $filterStatus);

        if ($filterSearch !== '') {
            $data['alerts'] = array_values(array_filter($data['alerts'], function ($alert) use ($filterSearch) {
                $nameMatch = str_contains(strtolower($alert['nama'] ?? ''), strtolower($filterSearch));
                $nipMatch = str_contains(strtolower($alert['nip'] ?? ''), strtolower($filterSearch));

                return $nameMatch || $nipMatch;
            }));
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
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
            'filterSearch' => $filterSearch,
            'filterEvent' => $filterEvent,
            'filterStatus' => $filterStatus,
            'typeLabels' => $data['type_labels'],
            'followupStatusLabels' => $data['followup_status_labels'],
        ]);
    }
}
