<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'application' => 'dolibarr',
            'idempotency_hash' => hash('sha256', Str::uuid()),
            'request_hash' => hash('sha256', Str::uuid()),
            'channel' => 'whatsapp',
            'template' => 'appointment_reminder',
            'recipient' => '+221770000001',
            'variables' => ['name' => 'Exemple'],
            'status' => 'pending',
        ];
    }
}
