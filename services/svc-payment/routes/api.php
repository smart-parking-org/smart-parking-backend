<?php

use App\Http\Controllers\ParkingLotController;
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});

// Parking slots
Route::prefix('parking-lots')->group(function () {
    Route::get('/', [ParkingLotController::class, 'index']);
    Route::get('/{id}', [ParkingLotController::class, 'show']);
    Route::get('/{id}/slots', [ParkingLotController::class, 'slotMap']);
    Route::get('/{id}/statistics', [ParkingLotController::class, 'statistics']);
});

// Slots
Route::prefix('slots')->group(function () {
    Route::get('/', [ParkingSlotController::class, 'index']);
    Route::get('/{id}', [ParkingSlotController::class, 'show']);
    Route::put('/{id}/status', [ParkingSlotController::class, 'updateStatus']);
});
