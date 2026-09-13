<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('public_id', 26)->unique();
            $table->string('application', 64);
            $table->string('name', 100);
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX api_keys_active_application_unique ON api_keys (tenant_id, application) WHERE revoked_at IS NULL');

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('api_keys');
    }
};
