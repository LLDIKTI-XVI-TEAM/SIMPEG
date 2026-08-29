<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ews\PimpinanEwsFilterRequest;

class PimpinanEwsController extends Controller
{
    public function index(PimpinanEwsFilterRequest $request, ListActiveEwsAlertsAction $action)
    {
        $validated = $request->validated();
        $filterSearch = trim((string) ($validated['search'] ?? ''));
        $filterEvent = (string) ($validated['event'] ?? '');
        $filterStatus = (string) ($validated['status'] ?? '');
        $perPage = (int) ($validated['per_page'] ?? 10);
        $data = $action->paginate($filterEvent, $filterStatus, $filterSearch, $perPage);

        return view('pimpinan.ews.index', [
            'alerts' => $data['alerts'],
            'filterSearch' => $filterSearch,
            'filterEvent' => $filterEvent,
            'filterStatus' => $filterStatus,
            'typeLabels' => $data['type_labels'],
            'followupStatusLabels' => $data['followup_status_labels'],
        ]);
    }
}
