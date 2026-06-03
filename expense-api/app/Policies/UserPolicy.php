<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    /**
     * Global pre-check: hard-deny any cross-company access before any ability runs.
     * The {user} route binding already scopes to company, but this is an explicit
     * second layer of defense (belt-and-suspenders).
     */
    public function before(User $authUser, string $ability, ?User $target = null): ?bool
    {
        if ($target && $target->company_id !== $authUser->company_id) {
            return false;
        }

        return null;
    }

    /** Only Admins may list all users in the company. */
    public function viewAny(User $authUser): bool
    {
        return $authUser->role === UserRole::Admin;
    }

    /** Admins may view any company user; any user may view themselves. */
    public function view(User $authUser, User $target): bool
    {
        return $authUser->role === UserRole::Admin
            || $authUser->id === $target->id;
    }

    /** Only Admins may create users. */
    public function create(User $authUser): bool
    {
        return $authUser->role === UserRole::Admin;
    }

    /** Only Admins may update other users. */
    public function update(User $authUser, User $target): bool
    {
        return $authUser->role === UserRole::Admin;
    }

    /**
     * Only Admins may delete users.
     * Self-deletion is blocked here at the policy layer; the controller
     * provides an explicit 422 guard with a descriptive message.
     */
    public function delete(User $authUser, User $target): bool
    {
        return $authUser->role === UserRole::Admin
            && $authUser->id !== $target->id;
    }
}
