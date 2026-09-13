<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Tenant;
use App\Services\Audit;
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
        DB::transaction(function (): void {
            $tenantId = Notification::whereKey($this->notificationId)->value('tenant_id');
            if (! $tenantId) {
                return;
            }
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->first();
            $notification = Notification::whereKey($this->notificationId)->lockForUpdate()->first();
            if (! $tenant || ! $notification || ! in_array($notification->status, ['pending', 'scheduled', 'queued'], true)
                || $notification->schedule_at?->isFuture()) {
                return;
            }
            $notification->status = $tenant->is_active ? 'awaiting_provider' : 'blocked';
            $notification->error_code = $tenant->is_active ? null : 'tenant_inactive';
            $notification->prepared_at = now();
            $notification->save();
            Audit::record('notification.'.$notification->status, $tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $notification->application]);
        });
    }
}
