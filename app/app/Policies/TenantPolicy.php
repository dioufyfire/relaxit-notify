<?php

namespace App\Policies;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->is_active && $tenant->is_active
            && ($user->platform_role !== null || $user->roleIn($tenant) !== null);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->platform_role === PlatformRole::SuperAdmin;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        if (! $this->view($user, $tenant)) {
            return false;
        }

        if ($user->platform_role !== null) {
            return $user->platform_role === PlatformRole::SuperAdmin;
        }

        return $user->roleIn($tenant) === TenantRole::Admin;
    }
}
