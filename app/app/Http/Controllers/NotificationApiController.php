<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationRequest;
use App\Models\Notification;
use App\Services\NotificationQueue;
use App\Services\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    public function store(StoreNotificationRequest $request, Notifications $notifications, NotificationQueue $queue): JsonResponse
    {
        $result = $notifications->accept($request->attributes->get('apiKey'), $request->validated(), $request->header('Idempotency-Key'));
        $notification = $result['notification'];
        $queue->enqueue($notification->id);

        return response()->json(['data' => $notification->fresh()->publicData(), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 202)
            ->header('Location', route('api.notifications.show', $notification->public_id));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $key = $request->attributes->get('apiKey');
        $notification = Notification::where('tenant_id', $key->tenant_id)->where('application', $key->application)->where('public_id', $id)->firstOrFail();

        return response()->json(['data' => $notification->publicData()]);
    }
}
