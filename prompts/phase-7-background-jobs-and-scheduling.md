# Phase 7: Background Jobs & Scheduling - Professional Copilot Prompt

## 🎯 Objective
Implement queued background processing on Redis and a weekly scheduled job that emails an expense report to all Admins of every company — using Laravel Queues, the scheduler (`schedule:run`), retry/backoff, and a Supervisor config for production.

> **Depends on:** Phases 1–4 (models + data) and Phase 6 (`QUEUE_CONNECTION=redis`).

## 📋 Implementation Requirements

### 7.1 Queue Configuration
`.env`:
```env
QUEUE_CONNECTION=redis
REDIS_QUEUE_DB=2          # isolate from cache DB
```
Create the failed-jobs + jobs tables (needed even with Redis for the `failed_jobs` table):
```bash
php artisan queue:table          # only if you also keep a database fallback
php artisan queue:failed-table
php artisan migrate
```

### 7.2 Weekly Expense Report Job
Create `app/Jobs/SendWeeklyExpenseReport.php`:
```php
<?php

namespace App\Jobs;

use App\Mail\WeeklyExpenseReportMail;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWeeklyExpenseReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Number of attempts before the job is marked failed. */
    public int $tries = 3;

    /** Exponential-ish backoff (seconds) between retries. */
    public array $backoff = [30, 120, 300];

    /** Max seconds the job may run. */
    public int $timeout = 120;

    public function handle(): void
    {
        $since = now()->subDays(7);

        // Process company-by-company to keep memory bounded and isolation intact.
        Company::query()->with('admins')->chunkById(50, function ($companies) use ($since) {
            foreach ($companies as $company) {
                $expenses = $company->expenses()
                    ->where('created_at', '>=', $since)
                    ->get(['id', 'title', 'amount', 'category', 'user_id', 'created_at']);

                if ($expenses->isEmpty()) {
                    continue;
                }

                $report = [
                    'company'      => $company->name,
                    'period_start' => $since->toDateString(),
                    'period_end'   => now()->toDateString(),
                    'total_amount' => (float) $expenses->sum('amount'),
                    'count'        => $expenses->count(),
                    'by_category'  => $expenses->groupBy('category')
                        ->map(fn ($g) => (float) $g->sum('amount')),
                ];

                $admins = $company->admins; // see relationship below
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(
                        new WeeklyExpenseReportMail($report)
                    );
                }

                Log::info('Weekly expense report sent', [
                    'company_id'    => $company->id,
                    'admin_count'   => $admins->count(),
                    'expense_count' => $report['count'],
                ]);
            }
        });
    }

    /** Called when the job ultimately fails after all retries. */
    public function failed(\Throwable $e): void
    {
        Log::error('SendWeeklyExpenseReport failed', ['error' => $e->getMessage()]);
    }
}
```

Add the `admins` relationship to `app/Models/Company.php`:
```php
public function admins(): HasMany
{
    return $this->hasMany(User::class)->where('role', 'Admin');
}
```

### 7.3 Report Mailable
```bash
php artisan make:mail WeeklyExpenseReportMail --markdown=emails.weekly-report
```
`app/Mail/WeeklyExpenseReportMail.php`:
```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyExpenseReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Weekly Expense Report — {$this->report['company']}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.weekly-report', with: ['report' => $this->report]);
    }
}
```
`resources/views/emails/weekly-report.blade.php`:
```blade
@component('mail::message')
# Weekly Expense Report — {{ $report['company'] }}

**Period:** {{ $report['period_start'] }} → {{ $report['period_end'] }}

**Total:** {{ number_format($report['total_amount'], 2) }}
**Expenses logged:** {{ $report['count'] }}

@component('mail::table')
| Category | Amount |
| :------- | -----: |
@foreach ($report['by_category'] as $category => $amount)
| {{ $category }} | {{ number_format($amount, 2) }} |
@endforeach
@endcomponent

Thanks,<br>{{ config('app.name') }}
@endcomponent
```

### 7.4 Scheduler Registration
**Laravel 11+** — `routes/console.php`:
```php
use App\Jobs\SendWeeklyExpenseReport;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SendWeeklyExpenseReport())
    ->weeklyOn(1, '08:00')   // Monday 08:00
    ->name('weekly-expense-report')
    ->withoutOverlapping()
    ->onOneServer();
```
**Laravel 10** — `app/Console/Kernel.php@schedule()`:
```php
$schedule->job(new SendWeeklyExpenseReport())
    ->weekly()->mondays()->at('08:00')
    ->withoutOverlapping()
    ->onOneServer();
```

### 7.5 Running Workers & Scheduler

**Local development:**
```bash
php artisan queue:work redis --tries=3 --backoff=30
php artisan schedule:work        # long-running dev scheduler
# or trigger once:
php artisan schedule:run
# dispatch the report immediately for testing:
php artisan tinker --execute="App\Jobs\SendWeeklyExpenseReport::dispatch();"
```

**Production cron** (single entry drives the whole scheduler):
```cron
* * * * * cd /var/www/expense-api && php artisan schedule:run >> /dev/null 2>&1
```

### 7.6 Supervisor (production queue worker)
`/etc/supervisor/conf.d/expense-worker.conf`:
```ini
[program:expense-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/expense-api/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/expense-api/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start expense-worker:*
```

## 🔍 Quality Gates

### Before Moving to Phase 8
1. ✅ **Worker processes jobs** — `php artisan queue:work` drains dispatched jobs.
2. ✅ **Report job runs** — manual dispatch logs "Weekly expense report sent" and (with `MAIL_MAILER=log`) writes emails to the log.
3. ✅ **Scheduler registered** — `php artisan schedule:list` shows the weekly job at Monday 08:00.
4. ✅ **Retry/backoff configured** — a forced failure retries up to 3× then lands in `failed_jobs`.
5. ✅ **Per-company isolation** — each Admin only receives their own company's totals.

## 🚀 Validation Commands
```bash
php artisan schedule:list
php artisan queue:work --once
php artisan tinker --execute="App\Jobs\SendWeeklyExpenseReport::dispatch();"
php artisan queue:failed          # inspect failures
php artisan queue:retry all
```

## 📝 Expected File Structure
```
app/Jobs/SendWeeklyExpenseReport.php
app/Mail/WeeklyExpenseReportMail.php
resources/views/emails/weekly-report.blade.php
routes/console.php  /  app/Console/Kernel.php   (schedule registration)
app/Models/Company.php                          (admins() relationship)
deploy/supervisor/expense-worker.conf           (production)
```

## ⚠️ Critical Implementation Notes
1. **Group strictly by company** — never aggregate expenses across tenants in a report.
2. **`chunkById`** keeps memory flat for large datasets.
3. **`withoutOverlapping()` + `onOneServer()`** prevent duplicate sends in multi-server deploys.
4. **`failed()` hook + `failed_jobs` table** make failures observable, not silent.
5. **Set `MAIL_MAILER=log` in dev** so no real mail is sent while testing.
6. **One cron line** (`schedule:run` every minute) drives all scheduled tasks in production.

## 🎯 Success Criteria
✅ Redis-backed queue processing jobs with retry/backoff
✅ Weekly report job groups by company and emails all Admins
✅ Scheduler runs the job Mondays at 08:00
✅ Failures captured in failed_jobs and logged
✅ Supervisor config ready for production workers
✅ Ready for Phase 8: Audit Logging
