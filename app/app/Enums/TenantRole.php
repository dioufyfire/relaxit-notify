<?php

namespace App\Enums;

enum TenantRole: string
{
    case Admin = 'admin_client';
    case User = 'user_client';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin Client',
            self::User => 'User Client',
            self::ReadOnly => 'Read Only',
        };
    }
}
