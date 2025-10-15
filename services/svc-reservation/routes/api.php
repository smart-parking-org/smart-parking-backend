<?php

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
