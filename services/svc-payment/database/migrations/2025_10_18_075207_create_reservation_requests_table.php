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
            $table->enum('vehicle_type', ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck']);
            $table->timestamp('desired_start_time')->nullable(); // Thời gian bắt đầu mong muốn
            $table->integer('duration_minutes')->nullable();     // Thời lượng đỗ (phút)
            $table->timestamp('requested_at')->nullable();
            $table->decimal('priority_score', 8, 2)->nullable();
            $table->foreignId('allocated_slot_id')->nullable()->constrained('parking_slots', 'id');
            $table->integer('processing_time_ms')->nullable();
            $table->enum('status', ['pending', 'assigned', 'failed', 'cancelled', 'completed'])->default('pending');
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
