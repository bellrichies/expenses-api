# Performance Baseline — Expense Management API

## Environment

| Setting | Value |
|---|---|
| PHP | 8.2 |
| Framework | Laravel 12 |
| Database | SQLite (dev) / MySQL (production) |
| Cache | Redis (DB 1) |
| Queue | Redis (DB 2) |

---

## N+1 Query Prevention

All list endpoints use explicit eager loading with column selection to prevent N+1 queries:

```php
Expense::query()
    ->forCompany($companyId)
    ->with(['user:id,name,company_id', 'company:id,name'])
    ->paginate($perPage);
```

### Enforced Assertion (PHPUnit)

```php
DB::enableQueryLog();
$this->getJson('/api/expenses')->assertOk();
expect(count(DB::getQueryLog()))->toBeLessThan(8);
```

### Observed Query Count (25 expenses, 1 company)

| Query | Purpose |
|---|---|
| 1 | `SELECT COUNT(*)` for pagination total |
| 2 | `SELECT * FROM expenses WHERE company_id = ?` |
| 3 | `SELECT id, name, company_id FROM users WHERE id IN (...)` |
| 4 | `SELECT id, name FROM companies WHERE id IN (...)` |

**Total: 4 queries** regardless of row count (constant, not O(n)).

---

## Index Utilisation

Composite index on `expenses(company_id, created_at)` confirmed:

```sql
-- SQLite
EXPLAIN QUERY PLAN
SELECT * FROM expenses WHERE company_id = 1 ORDER BY created_at DESC LIMIT 15;
-- → SEARCH expenses USING INDEX expenses_company_id_created_at_index (company_id=?)
```

Additional indexes:
- `expenses.user_id` — used by `forUser()` scope
- `users(company_id, email)` — unique constraint + query filter
- `audit_logs(company_id, created_at)` — audit trail pagination

---

## Stress Test Results

Seeder: `php artisan db:seed --class=ExpenseStressSeeder` creates 1,000 expenses across 5 companies (200 per company).

### Throughput Benchmark (Apache Bench)

```bash
ab -n 200 -c 10 \
   -H "Authorization: Bearer <ADMIN_TOKEN>" \
   http://localhost:8000/api/expenses
```

| Metric | Value |
|---|---|
| Requests/sec | ~180 rps |
| p50 latency | ~52 ms |
| p95 latency | ~95 ms |
| p99 latency | ~140 ms |
| DB queries / request | 4 (constant) |

> Numbers measured on a local Windows 11 machine with SQLite.
> MySQL on production hardware will be significantly faster.

---

## Redis Cache Impact

`GET /api/users` cache behaviour (Redis DB 1, TTL 3600 s):

| Request | Source | Latency |
|---|---|---|
| 1st call | Database | ~35 ms |
| 2nd call | Redis | ~5 ms |
| After mutation | Database (cache busted) | ~35 ms |

Cache key pattern: `laravel-database-laravel-cache-company.{id}.users`

---

## Recommendations for Production

1. Switch `DB_CONNECTION` to MySQL for full ENUM + JSON support.
2. Enable `REDIS_CACHE_DB=1`, `REDIS_QUEUE_DB=2` to isolate concerns.
3. Run `php artisan optimize` after every deploy to cache routes and config.
4. Monitor N+1 in production with Laravel Debugbar or Telescope.
5. Consider `php artisan queue:work redis --tries=3 --max-time=3600` via Supervisor (see `deploy/supervisor/expense-worker.conf`).
