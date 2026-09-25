<?php

namespace App\Console\Commands;

use App\Models\MetaWebhookReceipt;
use App\Services\MetaWebhookStatus;
use Illuminate\Console\Command;

class ReconcileMetaWebhooks extends Command
{
    protected $signature = 'relaxit:reconcile-meta-webhooks';

    protected $description = 'Rapprocher les statuts Meta vérifiés et purger les reçus expirés';

    public function handle(MetaWebhookStatus $statuses): int
    {
        MetaWebhookReceipt::whereNull('processed_at')->where('received_at', '<', now()->subDays(7))->delete();
        MetaWebhookReceipt::whereNotNull('processed_at')->where('received_at', '<', now()->subDays(30))->delete();
        $count = 0;
        $ids = MetaWebhookReceipt::whereNull('processed_at')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(500)->pluck('id');
        foreach ($ids as $id) {
            if ($statuses->reconcile($id)) {
                $count++;
            }
        }
        $this->info($count.' statut(s) Meta rapproché(s).');

        return self::SUCCESS;
    }
}
