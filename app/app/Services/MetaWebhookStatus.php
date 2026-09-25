<?php

namespace App\Services;

use App\Models\MetaWebhookReceipt;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MetaWebhookStatus
{
    public function receive(array $payload): void
    {
        abort_unless(($payload['object'] ?? null) === 'whatsapp_business_account', 400, 'Objet webhook invalide.');
        $validator = Validator::make($payload, [
            'entry' => ['required', 'array', 'max:100'],
            'entry.*' => ['array'],
            'entry.*.id' => ['required', 'string'],
            'entry.*.changes' => ['required', 'array', 'max:100'],
            'entry.*.changes.*' => ['array'],
            'entry.*.changes.*.field' => ['required', 'string'],
            'entry.*.changes.*.value' => ['required', 'array'],
        ]);
        abort_if($validator->fails(), 400, 'Structure webhook invalide.');
        $rows = [];
        foreach ($payload['entry'] as $entry) {
            if ($entry['id'] !== config('meta_whatsapp.waba_id')) {
                continue;
            }
            foreach ($entry['changes'] as $change) {
                $value = $change['value'];
                if ($change['field'] !== 'messages' || ($value['messaging_product'] ?? null) !== 'whatsapp'
                    || data_get($value, 'metadata.phone_number_id') !== config('meta_whatsapp.phone_number_id')) {
                    continue;
                }
                $events = $value['statuses'] ?? [];
                abort_unless(is_array($events) && count($events) <= 1000, 400);
                foreach ($events as $event) {
                    abort_unless(is_array($event), 400);
                    if (! in_array($event['status'] ?? null, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $valid = Validator::make($event, [
                        'id' => ['required', 'string', 'max:255', 'regex:/\Awamid\.[A-Za-z0-9_+=.\/-]+\z/'],
                        'timestamp' => ['required', 'regex:/\A[0-9]{1,10}\z/', 'integer', 'min:1', 'max:4102444800'],
                    ]);
                    abort_if($valid->fails(), 400, 'Statut webhook invalide.');
                    $code = data_get($event, 'errors.0.code');
                    $code = (is_int($code) || is_string($code)) && preg_match('/\A[0-9]{1,20}\z/', (string) $code) ? (string) $code : null;
                    $data = [
                        'waba_id' => $entry['id'],
                        'phone_number_id' => $value['metadata']['phone_number_id'],
                        'provider_message_id' => $event['id'],
                        'status' => $event['status'],
                        'error_code' => $event['status'] === 'failed' ? $code : null,
                        'occurred_at' => CarbonImmutable::createFromTimestampUTC((int) $event['timestamp'])->toDateTimeString(),
                    ];
                    $rows[] = ['event_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), ...$data,
                        'received_at' => now(), 'next_attempt_at' => now()];
                    abort_if(count($rows) > 1000, 413);
                }
            }
        }
        DB::transaction(function () use ($rows): void {
            if ($rows !== []) {
                DB::table('meta_webhook_receipts')->insertOrIgnore($rows);
            }
        });
    }

    public function reconcile(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $receipt = MetaWebhookReceipt::whereKey($id)->whereNull('processed_at')->lockForUpdate()->first();
            if (! $receipt) {
                return false;
            }
            $notification = Notification::where('provider_message_id', $receipt->provider_message_id)->lockForUpdate()->first();
            if (! $notification || ($notification->provider_phone_number_id ?? config('meta_whatsapp.phone_number_id')) !== $receipt->phone_number_id
                || ($notification->provider_waba_id ?? config('meta_whatsapp.waba_id')) !== $receipt->waba_id) {
                $receipt->next_attempt_at = now()->addMinutes(5);
                $receipt->save();

                return false;
            }
            $field = ['sent' => 'sent_at', 'delivered' => 'delivered_at', 'read' => 'read_at', 'failed' => 'delivery_failed_at'][$receipt->status];
            if ($notification->{$field} === null || $receipt->occurred_at->lt($notification->{$field})) {
                $notification->{$field} = $receipt->occurred_at;
            }
            $previous = $notification->status;
            // Delivery evidence wins over failure, and a read receipt can never regress.
            $status = $notification->read_at ? 'read' : ($notification->delivered_at ? 'delivered'
                : ($notification->delivery_failed_at ? 'failed' : 'sent'));
            $notification->status = $status;
            if ($status !== 'failed') {
                $notification->error_code = null;
            } elseif ($receipt->status === 'failed') {
                $notification->error_code = $receipt->error_code ? 'meta_delivery_'.$receipt->error_code : 'meta_delivery_failed';
            }
            $notification->save();
            if ($previous !== $status) {
                Audit::record('notification.'.$status, $notification->tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $notification->application]);
            }
            $receipt->notification_id = $notification->id;
            $receipt->processed_at = now();
            $receipt->save();

            return true;
        });
    }
}
