<?php

use App\Http\Controllers\ExtensionPolicyController;
use App\Http\Controllers\ParkingLotController;
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\PeakHourController;
use App\Http\Controllers\PricingRuleController;
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


// Thêm vào routes/api.php
Route::prefix('pricing-rules')->group(function () {
    Route::put('/{id}', [PricingRuleController::class, 'update']);
});

// Route cho lấy quy tắc giá theo bãi đỗ xe
Route::get('/parking-lots/{parkingLotId}/pricing-rules', [PricingRuleController::class, 'getByParkingLot']);

// Thêm vào routes/api.php
Route::prefix('peak-hours')->group(function () {
    Route::post('/', [PeakHourController::class, 'store']);
    Route::put('/{id}', [PeakHourController::class, 'update']);
    Route::delete('/{id}', [PeakHourController::class, 'destroy']);
});

// Route cho lấy giờ cao điểm theo bãi đỗ xe
Route::get('/parking-lots/{parkingLotId}/peak-hours', [PeakHourController::class, 'getByParkingLot']);


Route::prefix('extension-policies')->group(function () {
    Route::put('/{key}', [ExtensionPolicyController::class, 'update']);
    Route::get('/parking-lot/{parkingLotId}', [ExtensionPolicyController::class, 'getByParkingLot']);
});
