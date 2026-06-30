<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = collect();
        if (auth()->check() && auth()->user()->employee) {
            $notifications = auth()->user()->employee->notifications()->latest()->paginate(10);
        } else {
            $notifications = new LengthAwarePaginator([], 0, 10);
        }

        return view('admin.notifications.index', compact('notifications'));
    }
}
