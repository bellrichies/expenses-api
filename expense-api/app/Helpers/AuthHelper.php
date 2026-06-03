<?php

namespace App\Helpers;

use App\Enums\UserRole;
use Illuminate\Support\Facades\Auth;

class AuthHelper
{
    public static function getCompanyId(): ?int
    {
        return Auth::user()?->company_id;
    }

    public static function hasRole(UserRole $role): bool
    {
        return Auth::user()?->role === $role;
    }

    public static function hasAnyRole(UserRole ...$roles): bool
    {
        $userRole = Auth::user()?->role;
        return $userRole !== null && in_array($userRole, $roles, true);
    }

    public static function isAdmin(): bool
    {
        return self::hasRole(UserRole::Admin);
    }

    public static function isManager(): bool
    {
        return self::hasAnyRole(UserRole::Manager, UserRole::Admin);
    }

    public static function isEmployee(): bool
    {
        return self::hasRole(UserRole::Employee);
    }
}
