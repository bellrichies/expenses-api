<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    /**
     * Global pre-check: hard-deny any cross-company access before any ability runs.
     * Returning null lets the specific ability method decide.
     * Returning false short-circuits to a 403 immediately.
     */
    public function before(User $user, string $ability, ?Expense $expense = null): ?bool
    {
        if ($expense && $expense->company_id !== $user->company_id) {
            return false;
        }

        return null;
    }

    /** Any authenticated same-company user may list expenses. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * An expense owner may always view their own.
     * Managers and Admins may view any expense in the company.
     * (Company isolation is already guaranteed by before().)
     */
    public function view(User $user, Expense $expense): bool
    {
        return $expense->user_id === $user->id
            || in_array($user->role, [UserRole::Manager, UserRole::Admin], true);
    }

    /** All authenticated roles may create expenses. */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Employee, UserRole::Manager, UserRole::Admin], true);
    }

    /** Managers and Admins may update any expense in the company. */
    public function update(User $user, Expense $expense): bool
    {
        return in_array($user->role, [UserRole::Manager, UserRole::Admin], true);
    }

    /** Admins only may delete expenses. */
    public function delete(User $user, Expense $expense): bool
    {
        return $user->role === UserRole::Admin;
    }
}
