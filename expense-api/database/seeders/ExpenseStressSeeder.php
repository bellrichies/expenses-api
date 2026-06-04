<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseStressSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding stress dataset...');

        $companies = Company::factory()->count(5)->create();

        $users = collect();
        foreach ($companies as $company) {
            $admin = User::factory()->create([
                'company_id' => $company->id,
                'role'       => UserRole::Admin,
            ]);
            $employees = User::factory()->count(4)->create([
                'company_id' => $company->id,
                'role'       => UserRole::Employee,
            ]);
            $users = $users->merge([$admin])->merge($employees);
        }

        // 1,000 expenses spread across all companies
        $bar = $this->command->getOutput()->createProgressBar(1000);
        for ($i = 0; $i < 1000; $i++) {
            $user = $users->random();
            Expense::factory()->create([
                'company_id' => $user->company_id,
                'user_id'    => $user->id,
            ]);
            $bar->advance();
        }
        $bar->finish();

        $this->command->newLine();
        $this->command->info('Done. 1 000 expenses across 5 companies.');
    }
}
