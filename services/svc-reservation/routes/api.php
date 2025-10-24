<?php

use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\ParkingZoneController;
use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservation')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});


Route::prefix("parking-zones")->group(function () {
    Route::get('/', [ParkingZoneController::class, 'index']);
    Route::get('/{id}', [ParkingZoneController::class, 'show']);
    Route::post('/', [ParkingZoneController::class, 'store']);
    Route::patch('/{id}', [ParkingZoneController::class, 'update']);
    Route::delete('/{id}', [ParkingZoneController::class, 'destroy']);
    Route::post('/toggle-active/{id}', [ParkingZoneController::class, 'toogleActive']);
});

Route::prefix("parking-slots")->group(function () {
    // Route::get('/', [ParkingSlotController::class, 'index']);
    Route::get('/{id}', [ParkingSlotController::class, 'show']);
    Route::post('/', [ParkingSlotController::class, 'store']);
    Route::patch('/{id}', [ParkingSlotController::class, 'update']);
    Route::delete('/{id}', [ParkingSlotController::class, 'destroy']);
    Route::post('/toggle-active/{id}', [ParkingSlotController::class, 'toogleActive']);
});
