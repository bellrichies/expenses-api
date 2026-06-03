<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 1, 10000),
            'category' => fake()->randomElement(['Travel', 'Food', 'Office', 'Software', 'Marketing']),
        ];
    }
}
