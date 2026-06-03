<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DatabaseValidation extends Command
{
    protected $signature = 'db:validate';
    protected $description = 'Validate database schema and relationships';

    public function handle(): int
    {
        $this->info('Validating database schema...');

        $requiredTables = ['companies', 'users', 'expenses', 'audit_logs'];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Table '{$table}' does not exist!");
                return self::FAILURE;
            }
            $this->info("✓ Table '{$table}' exists");
        }

        // personal_access_tokens is added in Phase 2 (Sanctum)
        if (Schema::hasTable('personal_access_tokens')) {
            $this->info("✓ Table 'personal_access_tokens' exists");
        } else {
            $this->warn("  Table 'personal_access_tokens' not yet present (added in Phase 2)");
        }

        $this->info('Testing relationships...');

        try {
            $company = Company::factory()->create();
            $user = User::factory()->create(['company_id' => $company->id]);

            if ($company->users()->count() !== 1) {
                $this->error('Company-user relationship failed!');
                return self::FAILURE;
            }
            $this->info('✓ Company-user relationship works');

            $expense = Expense::factory()->create([
                'user_id' => $user->id,
                'company_id' => $company->id,
            ]);

            if ($user->expenses()->count() !== 1) {
                $this->error('User-expense relationship failed!');
                return self::FAILURE;
            }
            $this->info('✓ User-expense relationship works');

            if ($company->expenses()->count() !== 1) {
                $this->error('Company-expense relationship failed!');
                return self::FAILURE;
            }
            $this->info('✓ Company-expense relationship works');

            // Verify scopes
            if (User::forCompany($company->id)->count() !== 1) {
                $this->error('User::forCompany scope failed!');
                return self::FAILURE;
            }
            $this->info('✓ User::forCompany scope works');

            if (Expense::forCompany($company->id)->count() !== 1) {
                $this->error('Expense::forCompany scope failed!');
                return self::FAILURE;
            }
            $this->info('✓ Expense::forCompany scope works');

            // Clean up
            $company->delete();

            $this->info('✓ All relationships and scopes validated successfully!');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Validation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
