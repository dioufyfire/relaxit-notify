<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MetaWebhookReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_hash' => hash('sha256', Str::uuid()),
            'waba_id' => '123456789',
            'phone_number_id' => '987654321',
            'provider_message_id' => 'wamid.'.Str::random(20),
            'status' => 'delivered',
            'occurred_at' => now(),
            'received_at' => now(),
            'next_attempt_at' => now(),
        ];
    }
}
