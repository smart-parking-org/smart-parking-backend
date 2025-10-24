<?php

use App\Http\Controllers\ParkingLotController;
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\PeakHourController;
use App\Http\Controllers\ExtensionPolicyController;
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

// Pricing Rules (Key-Value Structure)
Route::prefix('pricing-rules')->group(function () {
    Route::get('/', [PricingRuleController::class, 'index']);                    // Danh sách cấu hình bảng giá
    Route::get('/{key}', [PricingRuleController::class, 'show']);               // Chi tiết cấu hình theo key
    Route::put('/{key}', [PricingRuleController::class, 'update']);             // Cập nhật cấu hình (chỉ update)
    Route::get('/{key}/price', [PricingRuleController::class, 'getPrice']);     // Lấy giá theo loại xe
});

// Peak Hours (Key-Value Structure)
Route::prefix('peak-hours')->group(function () {
    Route::get('/', [PeakHourController::class, 'index']);                      // Danh sách cấu hình giờ cao điểm
    Route::post('/', [PeakHourController::class, 'store']);                     // Tạo cấu hình giờ cao điểm mới
    Route::get('/{key}', [PeakHourController::class, 'show']);                 // Chi tiết cấu hình theo key
    Route::put('/{key}', [PeakHourController::class, 'update']);                 // Cập nhật cấu hình (chỉ update)
    Route::get('/{key}/check', [PeakHourController::class, 'checkPeakHour']);   // Kiểm tra giờ cao điểm
});

// Extension Policies (Key-Value Structure)
Route::prefix('extension-policies')->group(function () {
    Route::get('/', [ExtensionPolicyController::class, 'index']);                      // Danh sách cấu hình chính sách gia hạn
    Route::post('/', [ExtensionPolicyController::class, 'store']);                    // Tạo cấu hình chính sách gia hạn mới
    Route::get('/{key}', [ExtensionPolicyController::class, 'show']);               // Chi tiết cấu hình theo key
    Route::put('/{key}', [ExtensionPolicyController::class, 'update']);              // Cập nhật cấu hình
    Route::get('/{key}/check', [ExtensionPolicyController::class, 'checkExtension']); // Kiểm tra khả năng gia hạn
});
