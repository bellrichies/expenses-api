<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function view(User $user, Expense $expense): bool
    {
        return $user->company_id === $expense->company_id;
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->company_id === $expense->company_id
            && in_array($user->role, [UserRole::Manager, UserRole::Admin], true);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->company_id === $expense->company_id
            && $user->role === UserRole::Admin;
    }
}
