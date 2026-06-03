<?php

use App\Http\Controllers\AuditLogController;
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
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('expenses', [ExpenseController::class, 'store']);

    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:Manager,Admin');

    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:Admin');

    // ── Admin-only routes ────────────────────────────────────────────────────
    Route::middleware('role:Admin')->group(function () {
        // User management
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);

        // Audit trail (read-only)
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    });
});
