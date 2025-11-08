<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware(['auth:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::prefix('forgot-password')->group(function () {
        Route::post('request-otp', [AuthController::class, 'requestOtp'])
            ->middleware('throttle:5,1');
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])
            ->middleware('throttle:10,1');
        Route::post('reset', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:10,1');
    });
});

// Vehicles
Route::prefix("vehicles")->group(function () {
    Route::get('/', [VehicleController::class, 'index']);
    Route::get('/{id}', [VehicleController::class, 'show']);
    Route::post('/', [VehicleController::class, 'store']);
    Route::patch('/{id}', [VehicleController::class, 'update']);
    Route::delete('/{id}', [VehicleController::class, 'destroy']);
    Route::post('/primary/{id}', [VehicleController::class, 'setPrimary']);
    Route::post('/toggle-active/{id}', [VehicleController::class, 'toogleActive']);
});


// FCM
Route::put('/me/fcm-token', [UserController::class, 'updateFcmToken'])->middleware('auth:api');
Route::get('/users/{id}/fcm-token', [UserController::class, 'getFcmToken']);
// Users
Route::prefix("users")->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::patch('/{id}', [UserController::class, 'update'])->middleware(['auth:api']);

    Route::middleware(['auth:api', 'ensure.admin'])->group(function () {
        Route::post('/', [UserController::class, 'store']);
        Route::post('/restore/{id}', [UserController::class, 'restore']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });
});
