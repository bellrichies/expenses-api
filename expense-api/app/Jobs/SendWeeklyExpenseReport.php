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

    public int $tries = 3;

    /** Exponential-ish backoff in seconds between retries. */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function handle(): void
    {
        $since = now()->subDays(7);

        // Process company-by-company to keep memory flat and tenant isolation intact.
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

                foreach ($company->admins as $admin) {
                    Mail::to($admin->email)->send(new WeeklyExpenseReportMail($report));
                }

                Log::info('Weekly expense report sent', [
                    'company_id'    => $company->id,
                    'admin_count'   => $company->admins->count(),
                    'expense_count' => $report['count'],
                ]);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendWeeklyExpenseReport failed', ['error' => $e->getMessage()]);
    }
}
