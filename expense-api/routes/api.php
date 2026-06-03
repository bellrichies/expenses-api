<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes — require Sanctum token + company scoping
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {

    // Auth utilities
    Route::get('/auth/user', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ── Expenses ────────────────────────────────────────────────────────────
    // Read + create: any authenticated role
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('expenses', [ExpenseController::class, 'store']);

    // Update — Manager or Admin only (belt); ExpensePolicy is the second gate (suspenders)
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:Manager,Admin');

    // Delete — Admin only
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:Admin');

    // ── User management ─────────────────────────────────────────────────────
    // All user routes are Admin-only; UserPolicy + scoped binding enforce company isolation.
    Route::middleware('role:Admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
    });
});
