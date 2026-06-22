<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $employeeId = $request->user()?->employee_id;
        $items = $this->notifications
            ->latestForEmployee($employeeId)
            ->map(fn ($notification) => $notification->toApiArray())
            ->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'unread_count' => $this->notifications->unreadCountForEmployee($employeeId),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $this->notifications->unreadCountForEmployee($request->user()?->employee_id),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $this->notifications->markAsReadForEmployee($notificationId, $request->user()?->employee_id);

        if ($notification === null) {
            abort(404);
        }

        return response()->json(['data' => $notification->toApiArray()]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'updated' => $this->notifications->markAllAsReadForEmployee($request->user()?->employee_id),
            ],
        ]);
    }
}
