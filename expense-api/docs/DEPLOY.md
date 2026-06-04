# Production Deployment Guide

## Overview

This guide covers deploying the Expense Management API to a Linux production server running Nginx + PHP-FPM + MySQL + Redis.

---

## 1 — Server Requirements

| Component | Requirement |
|---|---|
| OS | Ubuntu 22.04 LTS (or equivalent) |
| PHP | 8.2+ with extensions: `pdo_mysql`, `redis` (or `predis`), `openssl`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` |
| MySQL | 8.0+ |
| Redis | 6.2+ |
| Composer | 2.x |
| Nginx | 1.18+ |
| Supervisor | 4.x |
| Certbot | (for TLS) |

---

## 2 — Initial Server Setup

```bash
# Clone the repository
git clone https://github.com/<owner>/expenses-api.git /var/www/expense-api
cd /var/www/expense-api/expense-api

# Install production dependencies only
composer install --no-dev --optimize-autoloader

# Set permissions
chown -R www-data:www-data /var/www/expense-api
chmod -R 755 /var/www/expense-api/expense-api/storage
chmod -R 755 /var/www/expense-api/expense-api/bootstrap/cache
```

---

## 3 — Environment Configuration

```bash
cp .env.example .env
# Edit .env with production values — see .env.production template in repo root
php artisan key:generate
```

Critical `.env` settings for production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expense_saas
DB_USERNAME=expense_user
DB_PASSWORD=<strong-password>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<redis-password>
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2

SANCTUM_STATEFUL_DOMAINS=app.yourdomain.com

MAIL_MAILER=smtp
MAIL_HOST=smtp.yourdomain.com
MAIL_PORT=587
MAIL_USERNAME=<user>
MAIL_PASSWORD=<pass>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Expense API"
```

---

## 4 — Database Setup

```bash
# Create the MySQL database and user
mysql -u root -p <<'SQL'
CREATE DATABASE expense_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'expense_user'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON expense_saas.* TO 'expense_user'@'localhost';
FLUSH PRIVILEGES;
SQL

# Run migrations
php artisan migrate --force
```

---

## 5 — Production Optimisation

Run after **every** deploy:

```bash
php artisan config:cache       # cache config/auth/sanctum etc.
php artisan route:cache        # cache all route definitions
php artisan event:cache        # cache observer/listener discovery
php artisan view:cache         # pre-compile Blade views (emails)
php artisan optimize           # runs all of the above
```

> **Re-run `php artisan optimize` after every code deployment** — the cache persists across deploys and must be rebuilt.

---

## 6 — Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;
    root /var/www/expense-api/expense-api/public;

    ssl_certificate     /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    # Force HTTPS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

Obtain a free TLS certificate:
```bash
certbot --nginx -d api.yourdomain.com
```

---

## 7 — Supervisor (Queue Workers)

The Supervisor config ships at `deploy/supervisor/expense-worker.conf`:

```ini
[program:expense-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/expense-api/expense-api/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/expense-api/expense-api/storage/logs/worker.log
```

```bash
sudo cp deploy/supervisor/expense-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start expense-worker:*

# Verify workers are running
sudo supervisorctl status
```

---

## 8 — Cron Scheduler

Add a single cron entry that drives all Laravel scheduled tasks:

```bash
sudo crontab -e -u www-data
```

```cron
* * * * * cd /var/www/expense-api/expense-api && php artisan schedule:run >> /dev/null 2>&1
```

This runs the **weekly expense report** job every Monday at 08:00 (configured in `routes/console.php`).

---

## 9 — Redis Persistence

Edit `/etc/redis/redis.conf`:

```conf
# Enable AOF persistence (recommended)
appendonly yes
appendfsync everysec

# Separate databases for cache and queue
# (configured via REDIS_CACHE_DB and REDIS_QUEUE_DB env vars)
databases 16
```

```bash
sudo systemctl restart redis
```

---

## 10 — Database Backups

Using `spatie/laravel-backup`:

```bash
composer require spatie/laravel-backup
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
```

Schedule daily backups in `routes/console.php`:

```php
Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:clean')->dailyAt('01:00');
```

Or use managed snapshots (AWS RDS automated backups, DigitalOcean managed DB, etc.).

---

## 11 — Error Tracking (Sentry)

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=https://<key>@o<id>.ingest.sentry.io/<project>
```

Add to `.env`:
```env
SENTRY_LARAVEL_DSN=https://<key>@o<id>.ingest.sentry.io/<project>
SENTRY_TRACES_SAMPLE_RATE=0.1
```

---

## 12 — Zero-Downtime Deployment

```bash
# 1. Put the app in maintenance mode with a friendly message
php artisan down --render="errors::503" --retry=60

# 2. Pull latest code
git pull origin main

# 3. Install dependencies
composer install --no-dev --optimize-autoloader

# 4. Run migrations safely
php artisan migrate --force

# 5. Rebuild all caches
php artisan optimize

# 6. Restart queue workers (they pick up new code on restart)
php artisan queue:restart

# 7. Bring the app back online
php artisan up
```

> **`queue:restart`** signals all workers to finish their current job and exit. Supervisor automatically restarts them with the new code.

---

## 13 — Health Check

The `/up` endpoint returns HTTP 200 if the application is healthy:

```bash
curl https://api.yourdomain.com/up
# → {"status":"up"}
```

Use this with load balancers, uptime monitors (Better Uptime, UptimeRobot), etc.

---

## 14 — Security Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] `APP_KEY` rotated and never committed to git
- [ ] `.env` excluded from git (in `.gitignore`)
- [ ] MySQL user has only `SELECT/INSERT/UPDATE/DELETE` permissions (not `SUPER`)
- [ ] Redis password set; bind to `127.0.0.1` only
- [ ] TLS/HTTPS enforced; HTTP redirects to HTTPS
- [ ] Rate limiting active on `/api/auth/login` and `/api/auth/register` (6 rpm)
- [ ] `Strict-Transport-Security` header set
- [ ] Backups scheduled and tested
- [ ] Sentry (or equivalent) monitoring active
