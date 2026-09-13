<?php

namespace App\Services;

use App\Jobs\PrepareNotification;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class NotificationQueue
{
    public function eligible(): Builder
    {
        return Notification::query()->where(function (Builder $query): void {
            $query->whereIn('status', ['pending', 'scheduled'])
                ->orWhere(fn (Builder $stale) => $stale->where('status', 'queued')->where('queued_at', '<=', now()->subMinutes(5)));
        })->where(fn (Builder $query) => $query->whereNull('schedule_at')->orWhere('schedule_at', '<=', now()));
    }

    public function enqueue(int $id): bool
    {
        $notification = DB::transaction(function () use ($id): ?Notification {
            $notification = $this->eligible()->whereKey($id)->lockForUpdate()->first();
            if (! $notification) {
                return null;
            }
            $notification->status = 'queued';
            $notification->queued_at = now();
            $notification->save();

            return $notification;
        });
        if (! $notification) {
            return false;
        }
        try {
            Queue::connection('redis')->push(new PrepareNotification($notification->id));
        } catch (Throwable) {
            // The durable row is retried after its lease, including ambiguous Redis acknowledgements.
            Log::warning('Notification queue unavailable; durable retry pending.', ['notification_id' => $notification->public_id]);

            return false;
        }

        return true;
    }
}
