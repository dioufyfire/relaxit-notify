<?php

namespace App\Console\Commands;

use App\Services\NotificationQueue;
use Illuminate\Console\Command;

class QueueNotifications extends Command
{
    protected $signature = 'relaxit:queue-notifications';

    protected $description = 'Mettre en file les notifications arrivées à échéance et reprendre les publications interrompues';

    public function handle(NotificationQueue $queue): int
    {
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
