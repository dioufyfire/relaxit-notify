<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Tenant;
use App\Services\Audit;
use App\Services\MetaWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PrepareNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $provider = app(MetaWhatsApp::class);
        $claimed = DB::transaction(function () use ($provider): ?Notification {
            $tenantId = Notification::whereKey($this->notificationId)->value('tenant_id');
            if (! $tenantId) {
                return null;
            }
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->first();
            $notification = Notification::whereKey($this->notificationId)->lockForUpdate()->first();
            if (! $tenant || ! $notification || ! in_array($notification->status, ['pending', 'scheduled', 'queued'], true)
                || $notification->schedule_at?->isFuture()) {
                return null;
            }
            $decision = $tenant->is_active ? $provider->disposition($notification, $tenant) : ['status' => 'blocked', 'error' => 'tenant_inactive'];
            $notification->status = $decision['status'];
            $notification->error_code = $decision['error'];
            $notification->prepared_at = now();
            if ($notification->status === 'sending') {
                $notification->send_started_at = now();
            }
            $notification->save();
            Audit::record('notification.'.$notification->status, $tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $notification->application]);

            return $notification->status === 'sending' ? $notification : null;
        });
        if (! $claimed) {
            return;
        }
        $result = $provider->send($claimed);
        DB::transaction(function () use ($claimed, $result): void {
            $notification = Notification::whereKey($claimed->id)->lockForUpdate()->first();
            if (! $notification || $notification->status !== 'sending') {
                return;
            }
            $notification->status = $result['status'];
            $notification->error_code = $result['error'];
            $notification->provider_message_id = $result['message_id'];
            $notification->submitted_at = $result['status'] === 'submitted' ? now() : null;
            $notification->save();
            Audit::record('notification.'.$notification->status, $notification->tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $notification->application]);
        });
    }
}
