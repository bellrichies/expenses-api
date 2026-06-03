<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        $this->log('create', $expense, [
            'old' => null,
            'new' => $expense->only(['title', 'amount', 'category']),
        ]);

        $this->bustCache($expense);
    }

    public function updated(Expense $expense): void
    {
        // getChanges() returns only the dirty attributes (post-save).
        $changed = $expense->getChanges();
        unset($changed['updated_at']);

        if (empty($changed)) {
            return;
        }

        $old = [];
        foreach (array_keys($changed) as $key) {
            $old[$key] = $expense->getOriginal($key);
        }

        $this->log('update', $expense, [
            'old' => $old,
            'new' => $changed,
        ]);

        $this->bustCache($expense);
    }

    public function deleted(Expense $expense): void
    {
        $this->log('delete', $expense, [
            'old' => $expense->only(['title', 'amount', 'category']),
            'new' => null,
        ]);

        $this->bustCache($expense);
    }

    /**
     * Falls back to the expense's owner when no HTTP user is authenticated
     * (e.g. queue jobs, seeders) so the FK never breaks.
     */
    private function log(string $action, Expense $expense, array $changes): void
    {
        AuditLog::create([
            'user_id'    => Auth::id() ?? $expense->user_id,
            'company_id' => $expense->company_id,
            'action'     => $action,
            'model_type' => Expense::class,
            'model_id'   => $expense->id,
            'changes'    => $changes,
        ]);
    }

    private function bustCache(Expense $expense): void
    {
        Cache::forget(CacheKeys::userExpenseSummary($expense->user_id));
        Cache::forget(CacheKeys::companyExpenseStats($expense->company_id));
    }
}
