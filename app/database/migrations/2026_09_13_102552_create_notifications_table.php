<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application', 64);
            $table->char('idempotency_hash', 64);
            $table->char('request_hash', 64);
            $table->string('channel', 20);
            $table->string('template', 100);
            $table->text('recipient');
            $table->text('variables');
            $table->text('external_reference')->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('schedule_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'application', 'idempotency_hash'], 'notifications_idempotency_unique');
            $table->index(['status', 'schedule_at']);
            $table->index(['status', 'queued_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
