<?php

namespace App\Providers;

use App\Models\Expense;
use App\Policies\ExpensePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register policies explicitly (auto-discovery also covers this)
        Gate::policy(Expense::class, ExpensePolicy::class);

        // Scope the {expense} route binding to the authenticated user's company.
        // A cross-tenant ID therefore returns 404 instead of leaking data.
        Route::bind('expense', function (string $value) {
            $query = Expense::where('id', $value);

            if ($user = request()->user()) {
                $query->where('company_id', $user->company_id);
            }

            return $query->firstOrFail();
        });
    }
}
