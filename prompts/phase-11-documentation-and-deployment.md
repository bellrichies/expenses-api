# Phase 11: Documentation & Deployment - Professional Copilot Prompt

## 🎯 Objective
Produce the production-ready package: API documentation (OpenAPI/Swagger + a Postman collection), a setup guide, a deployment guide, environment hardening, queue/scheduler process management, and the submission PR per the brief.

> **Depends on:** All prior phases. This is the final, ship-it phase.

## 📋 Implementation Requirements

### 11.1 API Documentation (OpenAPI / Swagger)
Install annotation-driven docs:
```bash
composer require darkaonline/l5-swagger
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```
Annotate controllers, e.g. above `ExpenseController@index`:
```php
/**
 * @OA\Get(
 *   path="/api/expenses",
 *   summary="List expenses (company-scoped, paginated, searchable)",
 *   tags={"Expenses"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
 *   @OA\Parameter(name="category", in="query", @OA\Schema(type="string")),
 *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Paginated expenses"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */
```
Generate + browse:
```bash
php artisan l5-swagger:generate
# visit http://localhost:8000/api/documentation
```
> Document **every** endpoint with request/response examples, required role, and rate limits. Keep the spec in sync with `php artisan route:list`.

### 11.2 Endpoint Reference Table (include in API.md)
| Method | Endpoint | Access | Notes |
|--------|----------|--------|-------|
| POST | `/api/register` | Public | Creates company + admin + token |
| POST | `/api/login` | Public | Returns token |
| POST | `/api/logout` | Authenticated | Revokes current token |
| GET | `/api/expenses` | All roles | Paginated, search, filter |
| POST | `/api/expenses` | All roles | Auto-assigns user + company |
| PUT | `/api/expenses/{id}` | Manager, Admin | Audited |
| DELETE | `/api/expenses/{id}` | Admin | Audited |
| GET | `/api/users` | Admin | Paginated, role filter |
| POST | `/api/users` | Admin | Async welcome email |
| PUT | `/api/users/{id}` | Admin | Role change audited |
| DELETE | `/api/users/{id}` | Admin | Self-delete blocked |
| GET | `/api/audit-logs` | Admin | Filterable trail |

### 11.3 Postman Collection
Export a collection (`docs/postman/ExpenseAPI.postman_collection.json`) with:
- A `{{base_url}}` and `{{token}}` environment variable.
- A login request whose **test script** stores the token: `pm.environment.set("token", pm.response.json().data.token)`.
- Folders mirroring the endpoint table above.

### 11.4 SETUP.md
Create `docs/SETUP.md`:
````markdown
# Local Setup

## Prerequisites
- PHP 8.2+, Composer, MySQL 8 / PostgreSQL 14, Redis 6+

## Steps
```bash
git clone <repo> && cd expense-api
composer install
cp .env.example .env
php artisan key:generate

# configure DB + Redis in .env, then:
php artisan migrate --seed
php artisan serve

# in separate terminals:
php artisan queue:work redis
php artisan schedule:work
```
## Default credentials (from seeder)
admin@demo.test / password
````

### 11.5 DEPLOY.md
Create `docs/DEPLOY.md` covering:
```bash
# Production optimization
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan migrate --force
```
- **Supervisor** for `queue:work` (config from Phase 7).
- **Cron** entry: `* * * * * php artisan schedule:run` (from Phase 7).
- **Redis persistence** (AOF/RDB) and a separate cache vs queue DB.
- **HTTPS/TLS** termination + force HTTPS.
- **DB backups** (e.g. `spatie/laravel-backup` or managed snapshots).
- **Error tracking** (Sentry): `composer require sentry/sentry-laravel`.
- **Zero-downtime**: `php artisan down --render` → deploy → `migrate --force` → `php artisan up`; restart workers (`queue:restart`).

### 11.6 Environment Hardening
`.env.production` essentials:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

SANCTUM_STATEFUL_DOMAINS=app.example.com
MAIL_MAILER=smtp
```
- Rate-limit auth routes: `Route::post('/login', ...)->middleware('throttle:6,1');`
- Ensure `APP_DEBUG=false` (no stack traces — ties to Phase 9).
- Rotate `APP_KEY` securely; never commit `.env`.

### 11.7 README & Submission (per brief)
Top-level `README.md` and PR description must include:
- **Full name** of the author.
- **Notes/assumptions** made (e.g., "first registered user is the company Admin").
- **Features implemented vs skipped** (with reasons) — map each to brief Tasks 1–6 + bonuses.
- **Testing instructions** (`php artisan test`, Postman import, seeded credentials).

Submission flow:
```bash
git checkout -b akintoye-sodeinde
git add . && git commit -m "Implement multi-tenant expense management API"
git push origin akintoye-sodeinde
# open PR -> original repo main branch
```

## 🔍 Quality Gates

### Definition of Done
1. ✅ Swagger UI lists **every** endpoint and matches `route:list`.
2. ✅ Postman collection runs end-to-end (login → CRUD → audit).
3. ✅ `SETUP.md` reproduces a working local env from scratch.
4. ✅ `DEPLOY.md` covers workers, scheduler, Redis, backups, HTTPS, error tracking.
5. ✅ `APP_DEBUG=false` and caches built in production.
6. ✅ PR description contains all brief-required sections.

## 🚀 Validation Commands
```bash
php artisan l5-swagger:generate
php artisan route:list
php artisan optimize         # config + route + event cache
php artisan about            # environment summary
```

## 📝 Expected File Structure
```
docs/SETUP.md
docs/DEPLOY.md
docs/API.md
docs/PERFORMANCE.md
docs/postman/ExpenseAPI.postman_collection.json
README.md
.env.example  /  .env.production
deploy/supervisor/expense-worker.conf
config/l5-swagger.php
```

## ⚠️ Critical Implementation Notes
1. **Docs must match reality** — regenerate Swagger and diff against `route:list` before submitting.
2. **Never commit secrets** — `.env` stays out of git; ship `.env.example`.
3. **Production caches** (`config:cache`, `route:cache`) require re-caching on every deploy.
4. **Restart workers after deploy** (`queue:restart`) or they run stale code.
5. **The PR description is graded** — address every bullet the brief asks for.

## 🎯 Success Criteria
✅ Complete OpenAPI/Swagger docs + Postman collection
✅ SETUP.md and DEPLOY.md enable reproducible install + deploy
✅ Production env hardened (debug off, HTTPS, rate limits, backups, Sentry)
✅ Supervisor + cron drive queue and scheduler
✅ PR submitted to main with all brief-required notes
✅ Project complete — all 6 brief tasks + bonuses delivered
