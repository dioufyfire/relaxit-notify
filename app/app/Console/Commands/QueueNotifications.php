<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\Audit;
use App\Services\NotificationQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueNotifications extends Command
{
    protected $signature = 'relaxit:queue-notifications';

    protected $description = 'Mettre en file les notifications arrivées à échéance et reprendre les publications interrompues';

    public function handle(NotificationQueue $queue): int
    {
        $stale = Notification::where('status', 'sending')->where('send_started_at', '<=', now()->subMinutes(5))->limit(500)->pluck('id');
        foreach ($stale as $id) {
            DB::transaction(function () use ($id): void {
                $notification = Notification::whereKey($id)->where('status', 'sending')->lockForUpdate()->first();
                if (! $notification) {
                    return;
                }
                $notification->status = 'delivery_unknown';
                $notification->error_code = 'meta_worker_interrupted';
                $notification->save();
                Audit::record('notification.delivery_unknown', $notification->tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $notification->application]);
            });
        }
        $count = 0;
        foreach ($queue->eligible()->orderBy('id')->limit(500)->pluck('id') as $id) {
            if ($queue->enqueue($id)) {
                $count++;
            }
        }
        $this->info($count.' notification(s) mise(s) en file.');

        return self::SUCCESS;
    }
}
