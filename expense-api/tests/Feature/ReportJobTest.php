<?php

namespace Tests\Feature;

use App\Jobs\SendWeeklyExpenseReport;
use App\Mail\WeeklyExpenseReportMail;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_report_to_all_admins_in_company(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $admin1  = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
        $admin2  = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
        // An employee — should NOT receive the report.
        User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Employee]);

        // Create recent expenses so the company is not skipped.
        Expense::factory()->count(3)->create([
            'company_id' => $company->id,
            'user_id'    => $admin1->id,
        ]);

        (new SendWeeklyExpenseReport())->handle();

        Mail::assertSent(WeeklyExpenseReportMail::class, 2);
        Mail::assertSent(WeeklyExpenseReportMail::class, fn ($m) => $m->hasTo($admin1->email));
        Mail::assertSent(WeeklyExpenseReportMail::class, fn ($m) => $m->hasTo($admin2->email));
    }

    public function test_job_skips_companies_with_no_recent_expenses(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
        // No expenses created — company should be skipped.

        (new SendWeeklyExpenseReport())->handle();

        Mail::assertNothingSent();
    }

    public function test_job_isolates_reports_per_company(): void
    {
        Mail::fake();

        $companyA = Company::factory()->create();
        $adminA   = User::factory()->create(['company_id' => $companyA->id, 'role' => UserRole::Admin]);
        Expense::factory()->count(2)->create([
            'company_id' => $companyA->id,
            'user_id'    => $adminA->id,
            'amount'     => 100,
        ]);

        $companyB = Company::factory()->create();
        $adminB   = User::factory()->create(['company_id' => $companyB->id, 'role' => UserRole::Admin]);
        Expense::factory()->count(3)->create([
            'company_id' => $companyB->id,
            'user_id'    => $adminB->id,
            'amount'     => 200,
        ]);

        (new SendWeeklyExpenseReport())->handle();

        // Each admin receives a report — but only for their own company.
        Mail::assertSent(WeeklyExpenseReportMail::class, 2);

        Mail::assertSent(WeeklyExpenseReportMail::class, function ($mail) use ($adminA, $companyA) {
            return $mail->hasTo($adminA->email)
                && $mail->report['company'] === $companyA->name
                && $mail->report['total_amount'] === 200.0;
        });

        Mail::assertSent(WeeklyExpenseReportMail::class, function ($mail) use ($adminB, $companyB) {
            return $mail->hasTo($adminB->email)
                && $mail->report['company'] === $companyB->name
                && $mail->report['total_amount'] === 600.0;
        });
    }

    public function test_report_data_includes_breakdown_by_category(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $admin   = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);

        Expense::factory()->create([
            'company_id' => $company->id, 'user_id' => $admin->id,
            'category' => 'Travel', 'amount' => 50,
        ]);
        Expense::factory()->create([
            'company_id' => $company->id, 'user_id' => $admin->id,
            'category' => 'Office', 'amount' => 30,
        ]);

        (new SendWeeklyExpenseReport())->handle();

        Mail::assertSent(WeeklyExpenseReportMail::class, function ($mail) {
            return isset($mail->report['by_category']['Travel'])
                && isset($mail->report['by_category']['Office']);
        });
    }
}
