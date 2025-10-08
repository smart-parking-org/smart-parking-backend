<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserApprovalController;
use App\Http\Controllers\VehicleTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refreshToken']);

    Route::middleware(['auth:api', 'ensure.access'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:api', 'ensure.access', 'ensure.admin'])->group(function () {
    // Vehicle types
    Route::apiResource('vehicle-types', VehicleTypeController::class)->only(['store', 'update', 'destroy']);

    Route::prefix('admin')->group(function () {
        Route::patch('/users/{id}/approve', [UserApprovalController::class, 'approve']);
        Route::patch('/users/{id}/reject', [UserApprovalController::class, 'reject']);

        // Vehicle types
        Route::post('/vehicle-types/{id}/activate', [VehicleTypeController::class, 'activate']);
        Route::post('/vehicle-types/{id}/deactivate', [VehicleTypeController::class, 'deactivate']);
    });
});

// Vehicle types
Route::apiResource('vehicle-types', VehicleTypeController::class)
    ->only(['index', 'show']);
