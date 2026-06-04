<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ── Public auth routes (rate-limited: 6 attempts/minute) ────────────────────
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/auth/login',    [AuthController::class, 'login'])->middleware('throttle:6,1');

// ── Protected routes — Sanctum token + company scoping ──────────────────────
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {

    // Auth utilities
    Route::get('/auth/user',  [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ── Expenses ─────────────────────────────────────────────────────────────
    Route::get('expenses',          [ExpenseController::class, 'index']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('expenses',          [ExpenseController::class, 'store']);

    // PUT — Manager or Admin only (route gate + ExpensePolicy second gate)
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:Manager,Admin');

    // DELETE — Admin only
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:Admin');

    // ── Admin-only routes ────────────────────────────────────────────────────
    Route::middleware('role:Admin')->group(function () {
        // User management
        Route::get('users',         [UserController::class, 'index']);
        Route::post('users',        [UserController::class, 'store']);
        Route::put('users/{user}',  [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);

        // Audit trail (read-only — write methods return 405)
        Route::get('audit-logs',            [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    });
});
