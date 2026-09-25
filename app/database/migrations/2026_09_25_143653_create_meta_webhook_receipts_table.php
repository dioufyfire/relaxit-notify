<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('event_hash', 64)->unique();
            $table->string('waba_id', 30);
            $table->string('phone_number_id', 30);
            $table->string('provider_message_id', 255);
            $table->string('status', 16);
            $table->string('error_code', 20)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('next_attempt_at');
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('notification_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['processed_at', 'next_attempt_at']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_webhook_receipts');
    }
};
