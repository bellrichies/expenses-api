<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\User;
use App\Policies\ExpensePolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\Model;
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
        // This is an API-only application — never redirect unauthenticated
        // requests to a login page. Always throw AuthenticationException so
        // the JSON exception renderer in bootstrap/app.php can respond.
        Authenticate::redirectUsing(fn () => null);

        // Throw on any lazy-loaded relation outside production so N+1 bugs
        // surface immediately in development and CI rather than silently
        // degrading performance in prod.
        Model::preventLazyLoading(! $this->app->environment('production'));

        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Scope {expense} binding to the authenticated user's company — cross-tenant
        // IDs resolve to 404 rather than leaking another tenant's data (IDOR prevention).
        Route::bind('expense', function (string $value) {
            $query = Expense::where('id', $value);

            if ($user = request()->user()) {
                $query->where('company_id', $user->company_id);
            }

            return $query->firstOrFail();
        });

        // Same isolation for {user} — an Admin can only resolve users in their own company.
        Route::bind('user', function (string $value) {
            $query = User::where('id', $value);

            if ($authUser = request()->user()) {
                $query->where('company_id', $authUser->company_id);
            }

            return $query->firstOrFail();
        });
    }
}
