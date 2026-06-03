# Phase 5: Authorization & Policies - Professional Copilot Prompt

## 🎯 Objective
Implement fine-grained, defense-in-depth authorization with Laravel Policies (`ExpensePolicy`, `UserPolicy`) layered on top of the route `role:` middleware, enforcing both **role hierarchy** and **company isolation** for every action.

> **Depends on:** Phases 1–4. Policies are invoked via `$this->authorize()` calls already present in the controllers.

## 📋 Implementation Requirements

### 5.1 ExpensePolicy
Create `app/Policies/ExpensePolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    /**
     * Global pre-check: deny any cross-company access outright.
     * Returning null lets the specific ability method decide.
     */
    public function before(User $user, string $ability, Expense $expense = null): ?bool
    {
        if ($expense && $expense->company_id !== $user->company_id) {
            return false; // hard deny — different tenant
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true; // any authenticated, same-company user
    }

    public function view(User $user, Expense $expense): bool
    {
        // Owner can view their own; Managers/Admins can view all in company.
        return $expense->user_id === $user->id
            || in_array($user->role, ['Manager', 'Admin'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['Employee', 'Manager', 'Admin'], true);
    }

    public function update(User $user, Expense $expense): bool
    {
        // Managers and Admins only.
        return in_array($user->role, ['Manager', 'Admin'], true);
    }

    public function delete(User $user, Expense $expense): bool
    {
        // Admins only.
        return $user->role === 'Admin';
    }
}
```

### 5.2 UserPolicy
Create `app/Policies/UserPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function before(User $authUser, string $ability, User $target = null): ?bool
    {
        if ($target && $target->company_id !== $authUser->company_id) {
            return false; // cross-company denial
        }

        return null;
    }

    public function viewAny(User $authUser): bool
    {
        return $authUser->role === 'Admin';
    }

    public function view(User $authUser, User $target): bool
    {
        // Admins, or a user viewing themselves.
        return $authUser->role === 'Admin' || $authUser->id === $target->id;
    }

    public function create(User $authUser): bool
    {
        return $authUser->role === 'Admin';
    }

    public function update(User $authUser, User $target): bool
    {
        return $authUser->role === 'Admin';
    }

    public function delete(User $authUser, User $target): bool
    {
        // Admins only, and never themselves (controller enforces self-guard).
        return $authUser->role === 'Admin' && $authUser->id !== $target->id;
    }
}
```

### 5.3 Register Policies
In **Laravel 11+** (no `AuthServiceProvider` by default) register inside `App\Providers\AppServiceProvider::boot()`:
```php
use App\Models\Expense;
use App\Models\User;
use App\Policies\ExpensePolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(Expense::class, ExpensePolicy::class);
    Gate::policy(User::class, UserPolicy::class);
}
```

For **Laravel 10** use `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    \App\Models\Expense::class => \App\Policies\ExpensePolicy::class,
    \App\Models\User::class    => \App\Policies\UserPolicy::class,
];

public function boot(): void
{
    $this->registerPolicies();
}
```

> Laravel also auto-discovers policies when named `{Model}Policy` in `app/Policies/`. Explicit registration is preferred for clarity and auditability.

### 5.4 Authorization Wiring in Controllers
The controllers from Phases 3–4 already call:
```php
$this->authorize('view', $expense);
$this->authorize('update', $expense);
$this->authorize('delete', $expense);
$this->authorize('update', $user);
$this->authorize('delete', $user);
```
Ensure the base `app/Http/Controllers/Controller.php` includes the authorization trait (Laravel 10):
```php
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller extends \Illuminate\Routing\Controller
{
    use AuthorizesRequests;
}
```

### 5.5 Map AuthorizationException → 403 JSON
Ensure unauthorized policy failures return the standard envelope. In `bootstrap/app.php` (Laravel 11) or `app/Exceptions/Handler.php` (Laravel 10):
```php
// Laravel 11 — bootstrap/app.php ->withExceptions(function ($exceptions) { ... })
use Illuminate\Auth\Access\AuthorizationException;

$exceptions->render(function (AuthorizationException $e, $request) {
    if ($request->expectsJson()) {
        return response()->json([
            'success' => false,
            'message' => 'This action is unauthorized.',
            'errors'  => [],
        ], 403);
    }
});
```

## 🔍 Quality Gates

### Before Moving to Phase 6
1. ✅ **Employee updating an expense → 403** (policy `update`).
2. ✅ **Manager deleting an expense → 403** (policy `delete`).
3. ✅ **Admin can update + delete** within their company.
4. ✅ **Any role accessing another company's expense/user → 403/404** (`before()` hard-deny + scoped binding).
5. ✅ **Non-admin on any `/api/users` action → 403**.

## 🚀 Validation Commands
```bash
# As Employee — expect 403
curl -X PUT http://localhost:8000/api/expenses/1 \
  -H "Authorization: Bearer EMPLOYEE_TOKEN" -H "Content-Type: application/json" \
  -d '{"amount":10}'

# Confirm policy bindings
php artisan tinker --execute="dump(app(\Illuminate\Contracts\Auth\Access\Gate::class)->getPolicyFor(App\Models\Expense::class));"
```

## 📝 Expected File Structure
```
app/Policies/ExpensePolicy.php
app/Policies/UserPolicy.php
app/Providers/AppServiceProvider.php   (or AuthServiceProvider.php — policy registration)
app/Http/Controllers/Controller.php    (AuthorizesRequests trait)
bootstrap/app.php / app/Exceptions/Handler.php (403 JSON mapping)
```

## ⚠️ Critical Implementation Notes
1. **`before()` is your tenant firewall** — a cross-company object is denied before any ability runs.
2. **Two layers must agree**: `role:` middleware (route) + policy (object). Middleware blocks by role class; policy blocks by ownership + company.
3. **Role hierarchy**: Admin ⊇ Manager ⊇ Employee for permitted actions — encode it explicitly, don't assume.
4. **Policies receive the resolved model**, which is already company-scoped by the route binding — but `before()` re-checks defensively.
5. **Always return consistent 403 JSON** so clients get a predictable error envelope.

## 🎯 Success Criteria
✅ ExpensePolicy + UserPolicy implemented and registered
✅ Role hierarchy enforced (view/create/update/delete)
✅ Cross-company access hard-denied at the policy layer
✅ AuthorizationException renders the standard 403 JSON envelope
✅ Ready for Phase 6: Query Optimization & Redis Caching
