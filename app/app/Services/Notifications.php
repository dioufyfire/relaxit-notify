<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Notification;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Notifications
{
    /** @return array{notification: Notification, replayed: bool} */
    public function accept(ApiKey $key, array $data, string $idempotencyKey): array
    {
        $data['schedule_at'] = isset($data['schedule_at']) ? CarbonImmutable::parse($data['schedule_at'])->utc()->toISOString() : null;
        $data['external_reference'] = $data['external_reference'] ?? null;
        ksort($data['variables']);
        ksort($data);
        $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));
        $idempotencyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($key, $data, $hash, $idempotencyHash): array {
            $tenant = Tenant::whereKey($key->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless($tenant->is_active, 401, 'Clé API absente ou invalide.');
            $existing = Notification::where('tenant_id', $tenant->id)->where('application', $key->application)
                ->where('idempotency_hash', $idempotencyHash)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Cette clé d’idempotence a déjà été utilisée avec un autre contenu.');

                return ['notification' => $existing, 'replayed' => true];
            }
            if ($data['schedule_at'] !== null && (CarbonImmutable::parse($data['schedule_at'])->isPast() || CarbonImmutable::parse($data['schedule_at'])->isAfter(now()->addYear()))) {
                throw ValidationException::withMessages(['schedule_at' => 'La date doit être future et située dans les 12 prochains mois.']);
            }
            $notification = new Notification;
            $notification->public_id = (string) Str::ulid();
            $notification->tenant_id = $tenant->id;
            $notification->api_key_id = $key->id;
            $notification->application = $key->application;
            $notification->idempotency_hash = $idempotencyHash;
            $notification->request_hash = $hash;
            foreach ($data as $field => $value) {
                $notification->{$field} = $value;
            }
            $notification->status = $data['schedule_at'] === null ? 'pending' : 'scheduled';
            $notification->save();
            Audit::record('notification.accepted', $tenant, metadata: ['notification_id' => $notification->public_id, 'application' => $key->application, 'key_id' => $key->id]);

            return ['notification' => $notification, 'replayed' => false];
        });
    }
}
