<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Notifications\PaginateNotificationsAction;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    public function index(PaginateNotificationsAction $action)
    {
        return view('admin.notifikasi.index', $action->execute(auth()->id()));
    }
}
