<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes — require Sanctum token + company scoping
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {
    Route::get('/auth/user', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Phase 3: Expense CRUD (uncomment when ExpenseController is created)
    // Route::apiResource('expenses', \App\Http\Controllers\ExpenseController::class);

    // Phase 4: User management — Admin only (uncomment when UserController is created)
    // Route::apiResource('users', \App\Http\Controllers\UserController::class)
    //     ->middleware('role:Admin');
});
