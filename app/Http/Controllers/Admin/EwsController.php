<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ews\ListActiveEwsAlertsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EwsController extends Controller
{
    /**
     * Menampilkan daftar EWS aktif dari alert database dengan filter dan eligibility.
     */
    public function index(Request $request, ListActiveEwsAlertsAction $action)
    {
        $filterEvent = (string) $request->query('event', '');
        $data = $action->execute($filterEvent);

        return view('admin.ews.aktif', [
            'alerts' => $data['alerts'],
            'filterEvent' => $filterEvent,
            'typeLabels' => $data['type_labels'],
            'title' => 'EWS Aktif',
        ]);
    }
}
