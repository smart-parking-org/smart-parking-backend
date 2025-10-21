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
    Route::get('/{id}/slots', [ParkingLotController::class, 'slotMap']);
    Route::get('/{id}/statistics', [ParkingLotController::class, 'statistics']);
    Route::get('/{id}/stream', [ParkingLotController::class, 'stream']);
});

// Slots
Route::prefix('slots')->group(function () {
    Route::get('/', [ParkingSlotController::class, 'index']);
    Route::get('/{id}', [ParkingSlotController::class, 'show']);
    Route::put('/{id}/status', [ParkingSlotController::class, 'updateStatus']);
});

// Reservations
Route::prefix('reservations')->group(function () {
    Route::get('/', [ReservationController::class, 'index']);           // Danh sách tất cả reservation
    Route::post('/', [ReservationController::class, 'store']);           // Đặt chỗ
    Route::get('/{id}', [ReservationController::class, 'show']);        // Chi tiết
    Route::put('/{id}/extend', [ReservationController::class, 'extend']); // Gia hạn
    Route::put('/{id}/cancel', [ReservationController::class, 'cancel']); // Hủy
    Route::put('/{id}/check-in', [ReservationController::class, 'checkIn']); // check-in
    Route::put('/{id}/check-out', [ReservationController::class, 'checkOut']); // check-out

    Route::post('/demo/check-in', [ReservationController::class, 'demoCheckIn']);
    Route::post('/demo/check-out', [ReservationController::class, 'demoCheckOut']);
    Route::post('/expire-due', [ReservationController::class, 'expireDue']);
});
