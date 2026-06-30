<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\GetUnreadNotificationCountAction;
use App\Actions\Notifications\ListNotificationsAction;
use App\Actions\Notifications\MarkAllNotificationsAsReadAction;
use App\Actions\Notifications\MarkNotificationAsReadAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, ListNotificationsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user()?->employee_id));
    }

    public function unreadCount(Request $request, GetUnreadNotificationCountAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $action->execute($request->user()?->employee_id),
            ],
        ]);
    }

    public function markAsRead(
        Request $request,
        string $notificationId,
        MarkNotificationAsReadAction $action,
    ): JsonResponse {
        $notification = $action->execute($notificationId, $request->user()?->employee_id);

        if ($notification === null) {
            abort(404);
        }

        return response()->json(['data' => $notification->toApiArray()]);
    }

    public function markAllAsRead(Request $request, MarkAllNotificationsAsReadAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                'updated' => $action->execute($request->user()?->employee_id),
            ],
        ]);
    }
}
