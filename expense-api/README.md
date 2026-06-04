# Multi-Tenant SaaS Expense Management API

**Author:** Akintoye Sodeinde  
**Stack:** Laravel 12 · PHP 8.2 · MySQL · Redis · Laravel Sanctum

---

## Overview

A secure, high-performance, multi-tenant REST API for managing company expenses. Each company's data is fully isolated — users can never access another company's expenses, users, or audit logs.

---

## Features Implemented

### ✅ Task 1 — Multi-Tenant Database Structure
- `companies`, `users` (with `company_id` FK + `role` enum), `expenses`, `audit_logs` tables
- Composite unique index `(company_id, email)` on users — same email permitted in different companies
- Composite index `(company_id, created_at)` on expenses for performant tenant-scoped queries
- All four models with relationships and query scopes

### ✅ Task 2 — Authentication & RBAC
- **Laravel Sanctum** stateless token authentication
- Three roles enforced at both middleware and policy layers:
  - **Admin** — full access: users + expenses + audit logs
  - **Manager** — update expenses; cannot delete or manage users
  - **Employee** — create and view own expenses only
- `CheckRole` middleware (first gate) + Laravel Policies (second gate — ExpensePolicy, UserPolicy)
- Company-scoped route binding: cross-company IDs return `404`, not `403` (prevents IDOR)

### ✅ Task 3 — API Endpoints

> **Note / Assumption:** The brief specifies `/api/register` and `/api/login`. This implementation uses `/api/auth/register` and `/api/auth/login` to follow RESTful grouping conventions. All other endpoint paths match the brief exactly.

| Method | Endpoint | Access |
|---|---|---|
| POST | `/api/auth/register` | Public — creates company + Admin + token |
| POST | `/api/auth/login` | Public — returns token |
| GET | `/api/auth/user` | Authenticated |
| POST | `/api/auth/logout` | Authenticated |
| GET | `/api/expenses` | All roles — paginated, searchable, filterable |
| POST | `/api/expenses` | All roles |
| PUT | `/api/expenses/{id}` | Manager, Admin |
| DELETE | `/api/expenses/{id}` | Admin only |
| GET | `/api/users` | Admin only |
| POST | `/api/users` | Admin only |
| PUT | `/api/users/{id}` | Admin only |
| DELETE | `/api/users/{id}` | Admin only |
| GET | `/api/audit-logs` | Admin only |
| GET | `/api/audit-logs/{id}` | Admin only |

### ✅ Task 4 — Optimisation & Performance
- Eager loading with column selection (`with(['user:id,name,company_id', 'company:id,name'])`) — zero N+1 queries
- `Model::preventLazyLoading()` active in development to surface any lazy loads immediately
- Redis caching for the company users list (TTL: 1 hour); invalidated on every mutation
- Cache-key helpers centralised in `App\Support\CacheKeys`
- N+1 assertion in the test suite: `GET /api/expenses` uses < 8 queries for 25 rows

### ✅ Task 5 — Background Job Processing
- `SendWeeklyExpenseReport` queued job — processes companies in chunks of 50, emails all Admins their company's weekly totals with per-category breakdown
- `SendWelcomeEmail` job dispatched async when Admin creates a new user
- Redis queue driver (`QUEUE_CONNECTION=redis`, `REDIS_QUEUE_DB=2`)
- Scheduler: weekly report fires every **Monday at 08:00** (`Schedule::job(...)->weeklyOn(1, '08:00')`)
- Production cron: `* * * * * php artisan schedule:run`
- Supervisor config for 2 worker processes in `deploy/supervisor/expense-worker.conf`

### ✅ Task 6 — Audit Logs
- `ExpenseObserver` registered via `#[ObservedBy]` attribute — cannot be bypassed by any controller path
- Logs every **create**, **update** (changed fields only, with old + new values), and **delete**
- Audit rows capture `user_id`, `company_id`, `model_type`, `model_id`, and a JSON `changes` column
- Read-only Admin API: `GET /api/audit-logs` with action/model/date filters

### ✅ Bonus Features
- **Standardised JSON envelope** (`ApiResponse` helper): `{success, message, data[, meta, errors]}`
- **`ForceJsonResponse` middleware** — API routes always return JSON, never HTML error pages
- **OpenAPI/Swagger docs** via `darkaonline/l5-swagger` — visit `/api/documentation`
- **Postman collection + environment** in `docs/postman/` — auto-captures token, IDs and assertions on every response
- **55 feature tests, 152 assertions** covering auth, RBAC, isolation, audit, N+1
- **`UserPolicy` self-delete guard** — Admins cannot lock a company out by deleting their own account
- **Per-company email uniqueness** — same email address allowed across different tenants

---

## Quick Start

```bash
# 1. Install
git clone <repo> && cd expense-api
composer install
cp .env.example .env
php artisan key:generate

# 2. Configure .env (MySQL + Redis — see docs/SETUP.md for SQLite alternative)
# DB_CONNECTION=mysql, DB_DATABASE=expense_saas …

# 3. Migrate
php artisan migrate

# 4. Serve
php artisan serve                          # http://localhost:8000
php artisan queue:work redis               # separate terminal
php artisan schedule:work                  # separate terminal (optional)

# 5. API docs
php artisan l5-swagger:generate
# Browse http://localhost:8000/api/documentation
```

---

## Testing

```bash
# Full suite (SQLite in-memory — no DB setup required)
php artisan test

# With coverage (requires Xdebug or PCOV)
php artisan test --coverage --min=80
```

**Current results: 55 tests · 152 assertions · 0 failures**

Test classes:
- `AuthTest` — registration, login, logout, profile, 401
- `ExpenseTest` — RBAC, cross-company isolation, audit logging, N+1 assertion
- `UserTest` — Admin-only CRUD, per-company email, queue assertion
- `AuditLogTest` — observer correctness, Admin-only API, append-only
- `ReportJobTest` — per-company report isolation, mail assertions

---

## Postman Collection

### Files

| File | Purpose |
|---|---|
| `docs/postman/Expenses_API.postman_collection.json` | All 14 endpoints across 4 folders with per-request test assertions and auto-variable scripts |
| `docs/postman/Expenses_API_Local.postman_environment.json` | Pre-configured environment for local development |

### Import

1. Open Postman → **Import** (top-left)
2. Drag and drop **both files** at once (collection + environment)
3. Select the **"Expenses API – Local"** environment from the environment dropdown (top-right)

### Environment Variables

All variables below are managed automatically by test scripts — you never need to set them manually after the initial import.

| Variable | Type | Set by | Cleared by |
|---|---|---|---|
| `base_url` | default | Pre-filled (`http://localhost:8000/api`) | — |
| `token` | secret | Register · Login | Logout |
| `user_id` | default | Register · Login | — |
| `company_id` | default | Register · Login | — |
| `expense_id` | default | Create Expense · List Expenses (first result) | Delete Expense |
| `target_user_id` | default | Create User · List Users (first non-Admin) | Delete User |
| `audit_log_id` | default | List Audit Logs (most recent entry) | — |

### Recommended Test Flow

```
1. Auth / Register          → saves token, user_id, company_id
2. Expenses / Create Expense → saves expense_id
3. Expenses / List Expenses  → confirms pagination meta, refreshes expense_id
4. Expenses / Show Expense   → validates expense shape
5. Expenses / Update Expense → Manager or Admin role required
6. Users / Create User       → saves target_user_id  (Admin token required)
7. Users / List Users        → refreshes target_user_id (prefers non-Admin)
8. Audit Logs / List         → saves audit_log_id    (Admin token required)
9. Audit Logs / Show         → validates audit log shape
10. Auth / Logout            → clears token
```

> **Testing multiple roles:** Use **Register** or **Login** with different credentials to swap the active token. The environment stores only one token at a time, so logging in as a Manager or Employee will replace the Admin token. Keep a second Postman environment (duplicate and rename) if you need both tokens simultaneously.

### Collection-Level Assertions

Every request automatically runs three baseline tests (defined at collection level):

- Status code is below `500`
- Response body is valid JSON
- Response contains a `success` boolean field

Individual requests layer additional assertions on top (field presence, status codes, pagination meta).

---

## API Documentation

Full endpoint reference: [`docs/API.md`](docs/API.md)  
Interactive Swagger UI: `php artisan l5-swagger:generate` → `/api/documentation`  
Deployment guide: [`docs/DEPLOY.md`](docs/DEPLOY.md)  
Local setup guide: [`docs/SETUP.md`](docs/SETUP.md)  
Performance baseline: [`docs/PERFORMANCE.md`](docs/PERFORMANCE.md)

---

## Assumptions & Notes

1. **Auth route prefix** — Used `/api/auth/register` and `/api/auth/login` instead of `/api/register` and `/api/login` for RESTful grouping. All other routes match the brief.
2. **First registered user is always Admin** — The `POST /api/auth/register` endpoint creates the company and assigns `Admin` role to the first user. Additional users are created by Admins via `POST /api/users`.
3. **Per-company email uniqueness** — The brief says "unique per company". The database enforces a composite `UNIQUE(company_id, email)` constraint. The same email can exist in multiple companies.
4. **Audit logging includes creates** — The brief specifies update/delete. The observer also logs creates (with `old: null`) for a complete trail.
5. **SQLite for development/testing** — Tests use SQLite in-memory. The production `.env.example` defaults to MySQL.
6. **Redis required** — As per the brief: cache (`REDIS_CACHE_DB=1`) and queue (`REDIS_QUEUE_DB=2`) both use Redis. The `predis/predis` package is used as the client (phpredis PECL extension not required).
7. **Soft deletes not implemented** — Expenses and users are hard-deleted. The audit log captures the deleted state (old values) before deletion.

---

## Project Structure

```
app/
├── Enums/UserRole.php              # Admin | Manager | Employee
├── Http/
│   ├── Controllers/                # Auth, Expense, User, AuditLog
│   ├── Middleware/                 # CheckRole, CompanyScope, ForceJsonResponse
│   ├── Requests/                   # Store/Update FormRequests with per-company rules
│   └── Resources/                  # Expense, User, AuditLog API resources
├── Jobs/                           # SendWelcomeEmail, SendWeeklyExpenseReport
├── Mail/                           # WelcomeUserMail, WeeklyExpenseReportMail
├── Models/                         # Company, User, Expense, AuditLog
├── Observers/ExpenseObserver.php   # Auto-audit on create/update/delete
├── Policies/                       # ExpensePolicy, UserPolicy
├── Providers/AppServiceProvider.php
└── Support/                        # ApiResponse, CacheKeys

database/
├── factories/                      # Company, User (admin/manager states), Expense, AuditLog
├── migrations/                     # All 7 migrations in correct dependency order
└── seeders/ExpenseStressSeeder.php # 1,000 expenses across 5 companies

deploy/supervisor/expense-worker.conf  # Production Supervisor config
docs/
├── API.md, SETUP.md, DEPLOY.md, PERFORMANCE.md
└── postman/
    ├── Expenses_API.postman_collection.json
    └── Expenses_API_Local.postman_environment.json
tests/Feature/                      # 55 tests covering all phases
```
