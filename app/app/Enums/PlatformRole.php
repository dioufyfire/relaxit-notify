<?php

namespace App\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin RelaxIT',
            self::Support => 'Support RelaxIT',
        };
    }
}
