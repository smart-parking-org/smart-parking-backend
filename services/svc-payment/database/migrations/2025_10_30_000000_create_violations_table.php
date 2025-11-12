<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->foreignId('reservation_id')->nullable()
                ->constrained('reservations', 'id')->nullOnDelete();
            $table->foreignId('parking_lot_id')->nullable()
                ->constrained('parking_lots', 'id')->nullOnDelete();
            $table->foreignId('slot_id')->nullable()
                ->constrained('parking_slots', 'id')->nullOnDelete();
            
            // Loại vi phạm
            $table->enum('type', [
                'OVERSTAY',                    // Đỗ quá giờ - Tự động phát hiện
                'LATE_CHECK_IN',               // Check-in muộn - Tự động phát hiện
                'PARKING_EXPIRED_CHECK_IN',    // Check-in khi reservation hết hạn - Tự động phát hiện
                'NO_SHOW',                     // Không đến - Tự động phát hiện
                'LATE_PAYMENT',                // Thanh toán phạt chậm - Tự động phát hiện
                'WRONG_SLOT',                  // Đỗ sai chỗ - Thủ công (Admin báo cáo)
                'NO_RESERVATION',              // Đỗ không có reservation - Thủ công (Admin báo cáo)
                'OTHER'                        // Khác - Thủ công
            ]);
            
            // Mức độ
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            
            // Trạng thái xử lý
            $table->enum('status', [
                'PENDING',      // Chờ xử lý
                'RESOLVED',     // Đã xử lý
                'CANCELLED',    // Đã hủy
                'APPEALED'      // Đang khiếu nại
            ])->default('PENDING');
            
            // Thông tin vi phạm
            $table->text('description')->nullable();      // Mô tả chi tiết
            $table->unsignedBigInteger('fine_amount')->nullable(); // Tiền phạt (VND)
            $table->string('evidence_url')->nullable();   // Hình ảnh/chứng cứ
            $table->string('ticket_number')->unique()->nullable(); // Số biên bản
            
            // Thông tin xử lý
            $table->unsignedBigInteger('resolved_by')->nullable(); // Admin xử lý
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();  // Ghi chú xử lý
            $table->timestamp('violation_time')->nullable(); // Thời điểm vi phạm
            
            // Liên kết thanh toán (nếu có)
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments', 'id')->nullOnDelete();
            
            // Dữ liệu snapshot
            $table->json('user_snapshot')->nullable();
            $table->json('vehicle_snapshot')->nullable();
            $table->json('reservation_snapshot')->nullable();
            $table->json('meta')->nullable(); // Thông tin bổ sung
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['vehicle_id', 'status']);
            $table->index(['reservation_id']);
            $table->index(['parking_lot_id', 'status']);
            $table->index(['status', 'type']);
            $table->index(['violation_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('violations');
    }
};

