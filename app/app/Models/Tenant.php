<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'address', 'phone', 'email'])]
class Tenant extends Model
{
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $query->where('is_active', true);

        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->platform_role !== null) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $members) => $members->where('users.id', $user->id));
    }
}
