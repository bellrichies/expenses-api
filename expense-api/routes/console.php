<?php

use App\Jobs\SendWeeklyExpenseReport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send weekly expense reports to all company Admins every Monday at 08:00.
// withoutOverlapping() + onOneServer() prevent duplicate sends in multi-worker deploys.
Schedule::job(new SendWeeklyExpenseReport())
    ->weeklyOn(1, '08:00')
    ->name('weekly-expense-report')
    ->withoutOverlapping()
    ->onOneServer();
