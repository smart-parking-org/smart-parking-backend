<?php

use App\Http\Controllers\ExtensionPolicyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MonthlyPassController;
use App\Http\Controllers\ParkingLotController;
use App\Http\Controllers\ParkingSlotController;
use App\Http\Controllers\PeakHourController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\ViolationController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});
use App\Http\Controllers\PaymentController;

Route::post('/payments/create', [PaymentController::class, 'create']);
Route::match(['get', 'post'], '/payments/return', [PaymentController::class, 'return']);
Route::match(['get', 'post'], '/payments/ipn', [PaymentController::class, 'ipn']);
Route::get('/payments/status/{reservation_id}', [PaymentController::class, 'status']);
Route::get('/payments/calculate/{reservation_id}', [PaymentController::class, 'calculate']);

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
    Route::get('/user/{user_id}/history', [ReservationController::class, 'getUserHistory']);
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

Route::prefix('notifications')->group(function () {
    Route::post('/send', [NotificationController::class, 'sendPushNotification']);
    Route::get('/', [NotificationController::class, 'index']);
    Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']); // Xóa một notification
    Route::delete('/delete-all', [NotificationController::class, 'deleteAll']); // Xóa tất cả notifications của user
});

// Monthly Passes
Route::prefix('monthly-passes')->group(function () {
    Route::post('/', [MonthlyPassController::class, 'store']);           // Tạo vé tháng + URL thanh toán
    Route::get('/', [MonthlyPassController::class, 'index']);            // Danh sách tất cả vé tháng (có phân trang)
    Route::get('/mine', [MonthlyPassController::class, 'mine']);         // Danh sách vé tháng của user
    Route::get('/{id}', [MonthlyPassController::class, 'show']);         // Chi tiết vé tháng
    Route::put('/{id}/cancel', [MonthlyPassController::class, 'cancel']); // Hủy vé tháng (chỉ khi PENDING)
});

// Violations - Tích hợp với Users (Yêu cầu đề tài: Quản lý cư dân - lịch sử vi phạm)
Route::get('/users/{user_id}/violations', [ViolationController::class, 'getUserViolations']);

// Violations
Route::prefix('violations')->group(function () {
    Route::get('/', [ViolationController::class, 'index']);                           // Danh sách vi phạm
    Route::post('/', [ViolationController::class, 'store']);                          // Tạo vi phạm thủ công (WRONG_SLOT, NO_RESERVATION, OTHER)
    Route::post('/detect-overstay', [ViolationController::class, 'detectOverstay']);  // Tự động phát hiện OVERSTAY
    Route::post('/detect-late-checkin', [ViolationController::class, 'detectLateCheckIn']); // Tự động phát hiện LATE_CHECK_IN
    Route::post('/detect-no-show', [ViolationController::class, 'detectNoShow']);     // Tự động phát hiện NO_SHOW
    Route::post('/detect-late-payment', [ViolationController::class, 'detectLatePayment']); // Tự động phát hiện LATE_PAYMENT
    Route::get('/mine', [ViolationController::class, 'mine']);                        // Vi phạm của user
    Route::get('/{id}', [ViolationController::class, 'show']);                        // Chi tiết vi phạm
    Route::put('/{id}/resolve', [ViolationController::class, 'resolve']);             // Xử lý vi phạm (Admin)
    Route::put('/{id}/cancel', [ViolationController::class, 'cancel']);               // Hủy vi phạm
    Route::post('/{id}/create-payment', [ViolationController::class, 'createPayment']); // Tạo thanh toán phạt
});
