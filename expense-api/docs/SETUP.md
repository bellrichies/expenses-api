# Local Development Setup

## Prerequisites

| Requirement | Minimum Version |
|---|---|
| PHP | 8.2+ |
| Composer | 2.x |
| MySQL **or** PostgreSQL | 8.0 / 14 |
| Redis | 6+ |
| Git | any |

> **SQLite** can be used for quick local hacking — the test suite always uses SQLite in-memory. For production and for full ENUM/JSON support, **MySQL is required**.

---

## 1 — Clone and install

```bash
git clone https://github.com/<your-fork>/expenses-api.git
cd expenses-api/expense-api

composer install
cp .env.example .env
php artisan key:generate
```

---

## 2 — Configure `.env`

Edit `.env` with your local values:

```env
APP_NAME="Expense Management API"
APP_URL=http://localhost:8000

# --- Database (MySQL recommended) ---
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expense_saas
DB_USERNAME=root
DB_PASSWORD=

# --- Redis ---
REDIS_CLIENT=predis          # or phpredis if the PECL extension is installed
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_CACHE_DB=1             # isolate cache keys
REDIS_QUEUE_DB=2             # isolate queue jobs

# --- Cache / Queue ---
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# --- Mail (logs to storage/logs/laravel.log in development) ---
MAIL_MAILER=log
```

> To use **SQLite** instead: set `DB_CONNECTION=sqlite` and `DB_DATABASE=/absolute/path/to/database.sqlite` (or leave blank to auto-create `database/database.sqlite`).

---

## 3 — Create the database and run migrations

```bash
# MySQL: create the database first
mysql -u root -e "CREATE DATABASE expense_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate
```

### Optional: seed demo data

```bash
# Seed 5 companies with 1,000 expenses for performance testing
php artisan db:seed --class=ExpenseStressSeeder

# Then register your own Admin via API or Tinker:
php artisan tinker
>>> App\Models\Company::factory()->create(['name' => 'Demo Co', 'email' => 'demo@demo.test']);
>>> App\Models\User::create(['company_id' => 1, 'name' => 'Admin', 'email' => 'admin@demo.test', 'password' => bcrypt('password'), 'role' => 'Admin']);
```

---

## 4 — Start the servers

Open **three terminals**:

```bash
# Terminal 1 — HTTP server
php artisan serve

# Terminal 2 — Queue worker
php artisan queue:work redis --tries=3 --sleep=3

# Terminal 3 — Scheduler (optional for local testing)
php artisan schedule:work
# OR trigger once:
php artisan schedule:run
```

The API is now available at **http://localhost:8000/api**.

---

## 5 — Generate API docs (Swagger UI)

```bash
php artisan l5-swagger:generate
# Browse: http://localhost:8000/api/documentation
```

---

## 6 — Run the test suite

```bash
# All tests use SQLite in-memory — no DB setup required
php artisan test

# With coverage (requires Xdebug or PCOV)
php artisan test --coverage --min=80
```

Current results: **55 tests, 152 assertions, 0 failures**.

---

## Environment Quick Reference

| Key | Local default | Production |
|---|---|---|
| `DB_CONNECTION` | sqlite | mysql |
| `CACHE_STORE` | array (tests) / redis | redis |
| `QUEUE_CONNECTION` | sync (tests) / redis | redis |
| `MAIL_MAILER` | log | smtp |
| `APP_DEBUG` | true | **false** |

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Class "Redis" not found` | Set `REDIS_CLIENT=predis` and run `composer require predis/predis` |
| `Connection refused` on Redis | Start Redis: `redis-server` |
| Migrations fail on SQLite | Ensure the SQLite file exists: `touch database/database.sqlite` |
| 500 on fresh install | Clear and rebuild caches: `php artisan optimize:clear && php artisan migrate` |
