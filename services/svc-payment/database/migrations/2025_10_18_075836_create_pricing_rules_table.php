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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained('parking_lots', 'id')->cascadeOnDelete();

            $table->enum('vehicle_type', ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck']);

            // --- Cấu hình giá ---
            $table->decimal('hourly', 10, 2);              // giá mỗi giờ
            $table->decimal('daily_cap', 10, 2)->nullable();   // mức trần 1 ngày
            $table->decimal('monthly_pass', 10, 2)->nullable(); // giá vé tháng

            // --- Cao điểm ---
            $table->boolean('peak_enabled')->default(false);     // có áp dụng giờ cao điểm không
            $table->decimal('peak_multiplier', 5, 2)->nullable(); // hệ số giờ cao điểm (ví dụ 1.5x)

            $table->timestamps();
            $table->unique(['parking_lot_id', 'vehicle_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
