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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();

            $table->foreignId('slot_id')->constrained('parking_slots', 'id')->cascadeOnDelete();
            $table->string('reservation_code', 50)->unique();

            // Trạng thái
            $table->enum('status', [
                'pending',      // đã gửi yêu cầu, chưa xác nhận
                'confirmed',    // đã giữ chỗ thành công
                'checked_in',   // đã vào bãi
                'checked_out',  // đã rời bãi
                'cancelled',    // cư dân hủy
                'expired'       // quá hạn giữ chỗ
            ])->default('pending');

            // Thời gian xử lý
            $table->timestamp('reserved_at')->nullable();   // lúc xác nhận giữ chỗ
            $table->timestamp('expires_at')->nullable();    // thời điểm hết hạn giữ chỗ
            $table->timestamp('extended_at')->nullable();   // gia hạn 1 lần
            $table->timestamp('check_in_at')->nullable();   // lúc vào bãi
            $table->timestamp('check_out_at')->nullable();  // lúc rời bãi
            $table->timestamp('cancelled_at')->nullable();  // nếu bị hủy

            // Dữ liệu snapshot
            $table->json('user_snapshot')->nullable();      // tên, email, sdt, role
            $table->json('vehicle_snapshot')->nullable();   // biển số, loại xe
            $table->json('pricing_snapshot')->nullable();   // giá theo giờ,ngày,tháng, khung giờ cao điểm,...

            $table->timestamps();

            // Index hỗ trợ tìm kiếm
            $table->index('status');
            $table->index('user_id');
            $table->index('vehicle_id');
            $table->index('slot_id');
            $table->index('reservation_code');
            $table->index('expires_at');
            $table->index('reserved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
