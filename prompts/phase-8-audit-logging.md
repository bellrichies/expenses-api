# Phase 8: Audit Logging - Professional Copilot Prompt

## 🎯 Objective
Automatically record every **create/update/delete** on expenses (and role changes on users) into the `audit_logs` table via a Model Observer, capturing **old vs new values**, the acting user, and the company — then expose a read-only, Admin-only audit trail API.

> **Depends on:** Phase 1 (`AuditLog` model + migration), Phase 2 (auth), Phase 5 (policies).

## 📋 Implementation Requirements

### 8.1 ExpenseObserver
Create `app/Observers/ExpenseObserver.php`:
```php
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
     * Persist a single audit record. Falls back gracefully to the
     * expense's own owner/company when there is no authenticated user
     * (e.g. seeders, queued jobs).
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
```

### 8.2 Register the Observer
**Laravel 11+** — attribute on the model (cleanest) in `app/Models/Expense.php`:
```php
use App\Observers\ExpenseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(ExpenseObserver::class)]
class Expense extends Model { /* ... */ }
```
**Laravel 10** — in `app/Providers/AppServiceProvider::boot()`:
```php
use App\Models\Expense;
use App\Observers\ExpenseObserver;

Expense::observe(ExpenseObserver::class);
```

### 8.3 (Optional) Reusable change-diff helper
If you prefer logging from controllers instead of (or in addition to) the observer:
```php
private function diff(array $before, array $after): array
{
    $changes = [];
    foreach ($after as $key => $value) {
        if (! array_key_exists($key, $before) || $before[$key] !== $value) {
            $changes[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
        }
    }
    return $changes;
}
```
> **Prefer the observer** — it cannot be bypassed by a forgotten controller path, which is exactly the guarantee an audit trail needs.

### 8.4 AuditLog Resource
Create `app/Http/Resources/AuditLogResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'model_type' => class_basename($this->model_type),
            'model_id'   => $this->model_id,
            'changes'    => $this->changes,
            'user'       => $this->whenLoaded('user', fn () => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

### 8.5 AuditLogController (read-only, Admin-only)
Create `app/Http/Controllers/AuditLogController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $query = AuditLog::query()
            ->forCompany($companyId)
            ->with('user:id,name');

        if ($action = $request->string('action')->toString()) {
            $query->forAction($action);            // create | update | delete
        }
        if ($type = $request->string('model_type')->toString()) {
            $query->where('model_type', 'like', "%{$type}%");
        }
        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'message' => 'Audit logs retrieved successfully',
            'data'    => AuditLogResource::collection($logs)->resolve(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
                'last_page'    => $logs->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_if($auditLog->company_id !== $request->user()->company_id, 404);

        $auditLog->load('user:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Audit log retrieved successfully',
            'data'    => new AuditLogResource($auditLog),
        ]);
    }
}
```

### 8.6 Routes
Add to `routes/api.php`:
```php
use App\Http\Controllers\AuditLogController;

Route::middleware(['auth:sanctum', 'company.scope', 'role:Admin'])->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
});
```

## 🔍 Quality Gates

### Before Moving to Phase 9
1. ✅ **Update logs old + new** — change an expense amount; the audit row's `changes` shows `{old:{amount:..},new:{amount:..}}`.
2. ✅ **Delete logs the prior state**; create logs `old:null`.
3. ✅ **Audit row carries acting `user_id` + `company_id`**.
4. ✅ **Audit API is Admin-only and company-scoped** — non-admin → 403; cross-company id → 404.
5. ✅ **Observer cannot be bypassed** — expenses changed via Tinker still produce audit rows.

## 🚀 Validation Commands
```bash
php artisan tinker
>>> $e = App\Models\Expense::first();
>>> $e->update(['amount' => $e->amount + 50]);
>>> App\Models\AuditLog::latest()->first()->changes;   // {old:..., new:...}

curl "http://localhost:8000/api/audit-logs?action=update" -H "Authorization: Bearer ADMIN_TOKEN"
```

## 📝 Expected File Structure
```
app/Observers/ExpenseObserver.php
app/Http/Controllers/AuditLogController.php
app/Http/Resources/AuditLogResource.php
app/Models/Expense.php                 (ObservedBy attribute — L11)
app/Providers/AppServiceProvider.php   (Expense::observe — L10)
routes/api.php                          (updated)
```

## ⚠️ Critical Implementation Notes
1. **Observer over controller logging** — guarantees completeness; no code path can skip the audit.
2. **`getChanges()` after save** yields the dirty set; pair with `getOriginal()` for the old values.
3. **`changes` is cast to array/JSON** (Phase 1) — store the `{old, new}` structure directly.
4. **Audit logs are append-only** — expose only `index`/`show`; never update or delete them via API.
5. **Fallback `user_id`** to the model owner for non-HTTP contexts (seeders, jobs) so the FK never breaks.
6. **Strip `updated_at`** from the diff to avoid noisy timestamp-only audit entries.

## 🎯 Success Criteria
✅ Every expense create/update/delete is audited automatically
✅ Old and new values captured accurately
✅ Acting user and company recorded on each entry
✅ Read-only audit trail API, Admin-only and company-scoped
✅ Observer-driven (un-bypassable) logging
✅ Ready for Phase 9: API Response Standardization
