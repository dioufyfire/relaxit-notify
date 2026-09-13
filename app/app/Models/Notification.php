<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'scheduled', 'queued', 'awaiting_provider', 'blocked'];

    protected $hidden = ['recipient', 'variables', 'external_reference', 'request_hash', 'idempotency_hash'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'variables' => 'encrypted:array',
            'external_reference' => 'encrypted',
            'schedule_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'prepared_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function publicData(): array
    {
        return [
            'id' => $this->public_id,
            'application' => $this->application,
            'channel' => $this->channel,
            'template' => $this->template,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'schedule_at' => $this->schedule_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'prepared_at' => $this->prepared_at?->toISOString(),
        ];
    }
}
