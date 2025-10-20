<?php

use App\Http\Controllers\ParkingLotController;
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});
use App\Http\Controllers\PaymentController;

Route::post('/payments/create', [PaymentController::class, 'create']);
Route::match(['get', 'post'], '/payments/return', [PaymentController::class, 'return']);
Route::match(['get', 'post'], '/payments/ipn', [PaymentController::class, 'ipn']);


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

// Reservations
Route::prefix('reservations')->group(function () {
    Route::post('/', [ReservationController::class, 'store']);           // Đặt chỗ
    Route::get('/{id}', [ReservationController::class, 'show']);        // Chi tiết
    Route::put('/{id}/extend', [ReservationController::class, 'extend']); // Gia hạn
    Route::put('/{id}/cancel', [ReservationController::class, 'cancel']); // Hủy
});
