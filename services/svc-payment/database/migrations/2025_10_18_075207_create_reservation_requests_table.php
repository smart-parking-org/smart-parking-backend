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
        Schema::create('reservation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained('parking_lots', 'id')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();

            // Thông tin xe và thời gian
            $table->enum('vehicle_type', ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck']);
            $table->timestamp('desired_start_time')->nullable(); // Thời gian bắt đầu mong muốn
            $table->integer('duration_minutes')->nullable();     // Thời lượng đỗ (phút)
            $table->timestamp('requested_at')->nullable(); // Thời điểm tạo request

            // Kết quả phân bổ
            $table->foreignId('allocated_slot_id')->nullable()->constrained('parking_slots', 'id');
            $table->integer('processing_time_ms')->nullable(); // Thời gian xử lý thuật toán
            $table->enum(
                'status',
                [
                    'pending', // Chờ xử lý
                    'assigned',  // Đã cấp chỗ
                    'failed', // Không cấp được chỗ (xung đột)
                    'cancelled',  // User hủy
                    'completed' // Hoàn thành (sau khi check-out)
                ]
            )->default('pending');

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_requests');
    }
};
