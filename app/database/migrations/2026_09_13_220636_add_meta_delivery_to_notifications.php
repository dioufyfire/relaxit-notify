<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('provider_message_id', 255)->nullable()->unique();
            $table->timestampTz('send_started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->index(['status', 'send_started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'send_started_at']);
            $table->dropColumn(['provider_message_id', 'send_started_at', 'submitted_at']);
        });
    }
};
