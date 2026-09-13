<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['token_hash'])]
class ApiKey extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function displayData(): array
    {
        return [
            ...$this->only('id', 'public_id', 'application', 'name', 'created_at', 'expires_at', 'revoked_at', 'last_used_at'),
            'status' => $this->revoked_at ? 'Révoquée' : ($this->isUsable() ? 'Active' : 'Expirée'),
        ];
    }
}
