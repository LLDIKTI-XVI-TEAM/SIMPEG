<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Notifications\PaginateNotificationsAction;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function index(PaginateNotificationsAction $action)
    {
        return view('admin.notifications.index', $action->execute(auth()->user()?->employee_id));
    }
}
