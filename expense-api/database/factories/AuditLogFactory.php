<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['create', 'update', 'delete']),
            'model_type' => fake()->randomElement(['Expense', 'User']),
            'model_id' => fake()->numberBetween(1, 100),
            'changes' => ['old' => [], 'new' => []],
        ];
    }
}
