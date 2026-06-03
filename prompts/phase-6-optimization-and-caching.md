# Phase 6: Query Optimization & Redis Caching - Professional Copilot Prompt

## 🎯 Objective
Eliminate N+1 queries through eager loading and column selection, confirm the multi-tenant indexes are doing their job, and add **Redis** caching (compulsory per the brief) for frequently accessed data with disciplined invalidation.

> **Depends on:** Phases 1–4 (queries to optimize must exist first).

## 📋 Implementation Requirements

### 6.1 Redis Configuration
Update `.env`:
```env
CACHE_STORE=redis          # Laravel 11 (CACHE_DRIVER on Laravel 10)
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis       # or "predis" if the PHP extension is unavailable
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_CACHE_DB=1            # isolate cache from queue/session DBs
```
Install the client (choose one):
```bash
# Preferred: phpredis PECL extension (faster)
pecl install redis
# OR pure-PHP fallback:
composer require predis/predis
```
Verify connectivity:
```bash
php artisan tinker --execute="Cache::store('redis')->put('ping','pong',10); dump(Cache::store('redis')->get('ping'));"
redis-cli KEYS '*'
```

### 6.2 Eliminate N+1 with Eager Loading
Every list/detail endpoint must eager load with **explicit column selection** (already applied in Phases 3–4):
```php
// ✅ Good — one query for expenses + one per relation, columns trimmed
Expense::forCompany($companyId)
    ->with(['user:id,name,company_id', 'company:id,name'])
    ->paginate($perPage);
```
> When selecting columns on a `belongsTo` relation, **always include the foreign key** (`company_id`, owner key) or the relation will resolve to `null`.

#### N+1 Guardrail (development)
Enable strict mode in `AppServiceProvider::boot()` so a lazy load throws during tests/dev:
```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    Model::preventLazyLoading(! $this->app->isProduction());
    // ... policy registration from Phase 5
}
```
Optionally add Laravel Debugbar in dev to visualize query counts:
```bash
composer require barryvdh/laravel-debugbar --dev
```

### 6.3 Index Verification
The indexes were created in Phase 1. Confirm the planner uses them:
```sql
EXPLAIN SELECT * FROM expenses WHERE company_id = 1 ORDER BY created_at DESC LIMIT 15;
-- Expect: key = expenses_company_id_created_at_index (the composite index)
```
```bash
php artisan tinker --execute="DB::select('SHOW INDEX FROM expenses');"
```

### 6.4 Redis Caching Strategy

#### A reusable cache-key/TTL helper
Create `app/Support/CacheKeys.php`:
```php
<?php

namespace App\Support;

class CacheKeys
{
    public const TTL_USERS   = 3600; // 1 hour
    public const TTL_SUMMARY = 1800; // 30 minutes

    public static function companyUsers(int $companyId): string
    {
        return "company.{$companyId}.users";
    }

    public static function userExpenseSummary(int $userId): string
    {
        return "user.{$userId}.expenses.summary";
    }

    public static function companyExpenseStats(int $companyId): string
    {
        return "company.{$companyId}.expenses.stats";
    }
}
```

#### Cache reads (example: company users list)
```php
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

$users = Cache::remember(
    CacheKeys::companyUsers($companyId),
    CacheKeys::TTL_USERS,
    fn () => User::forCompany($companyId)->with('company:id,name')->get()
);
```

#### Per-user expense summary
```php
$summary = Cache::remember(
    CacheKeys::userExpenseSummary($user->id),
    CacheKeys::TTL_SUMMARY,
    fn () => [
        'total_amount' => (float) Expense::forUser($user->id)->sum('amount'),
        'count'        => Expense::forUser($user->id)->count(),
    ]
);
```

#### Invalidation (write-through busting)
Forget affected keys on every create/update/delete:
```php
Cache::forget(CacheKeys::companyUsers($companyId));        // on user mutations
Cache::forget(CacheKeys::userExpenseSummary($userId));     // on expense mutations
Cache::forget(CacheKeys::companyExpenseStats($companyId)); // on expense mutations
```
> Centralize invalidation in the `ExpenseObserver` (Phase 8) and `UserController` (Phase 4) so it never drifts. **Stale cache is a correctness bug, not just a perf issue.**

### 6.5 HTTP Cache Headers (optional, safe for GETs)
```php
return response()->json($payload)
    ->header('Cache-Control', 'private, max-age=60');
```
> Use `private` (never `public`) because responses are tenant-specific.

## 🔍 Quality Gates

### Before Moving to Phase 7
1. ✅ **Redis reachable** — `Cache::get()` round-trips; `redis-cli KEYS '*'` shows app keys.
2. ✅ **Zero N+1** — list endpoints execute a constant number of queries regardless of row count (Debugbar / `preventLazyLoading`).
3. ✅ **Indexes used** — `EXPLAIN` shows the composite index, not a full scan.
4. ✅ **Cache hit on second request** — second `GET /api/users` is served from Redis.
5. ✅ **Invalidation works** — creating a user immediately reflects in the next list response.

## 🚀 Validation Commands
```bash
redis-cli MONITOR &                      # watch live cache traffic
curl http://localhost:8000/api/users -H "Authorization: Bearer ADMIN_TOKEN"   # MISS -> sets key
curl http://localhost:8000/api/users -H "Authorization: Bearer ADMIN_TOKEN"   # HIT
redis-cli KEYS 'company.*'

# Confirm constant query count with a seeded large dataset
php artisan db:seed --class=ExpenseStressSeeder   # create 1000+ rows
```

## 📝 Expected File Structure
```
app/Support/CacheKeys.php
app/Providers/AppServiceProvider.php   (preventLazyLoading + reads)
.env                                   (Redis config)
config/cache.php / config/database.php (redis stores verified)
```

## ⚠️ Critical Implementation Notes
1. **Redis is compulsory** for this project (brief requirement) — both cache and queue connections.
2. **Always namespace cache keys by tenant** (`company.{id}.*`) so caches never bleed across companies.
3. **Include foreign keys** when selecting relation columns or eager loading silently breaks.
4. **Invalidate eagerly** on writes — prefer correctness over a longer TTL.
5. **Use `private` cache headers** — tenant data must never be shared by intermediaries.
6. **`preventLazyLoading` only outside production** to surface N+1 in tests without risking prod 500s.

## 🎯 Success Criteria
✅ Redis configured for cache (and queue, used in Phase 7)
✅ All list/detail endpoints free of N+1 queries
✅ Composite indexes confirmed in use via EXPLAIN
✅ Frequently accessed data cached with tenant-scoped keys + TTLs
✅ Cache invalidated correctly on every mutation
✅ Ready for Phase 7: Background Jobs & Scheduling
