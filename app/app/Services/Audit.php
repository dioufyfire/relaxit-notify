<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;

class Audit
{
    public static function record(string $action, ?Tenant $tenant = null, ?User $actor = null, array $metadata = []): void
    {
        $event = new AuditEvent;
        $event->tenant_id = $tenant?->id;
        $event->actor_id = $actor?->id;
        $event->action = $action;
        $event->ip_address = app()->runningInConsole() ? null : request()->ip();
        $event->metadata = array_intersect_key($metadata, array_flip(['key_id', 'replacement_key_id', 'application', 'fields', 'notification_id']));
        $event->created_at = now();
        $event->save();
    }
}
