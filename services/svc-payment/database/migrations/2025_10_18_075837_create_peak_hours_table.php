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
        Schema::create('peak_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained('parking_lots', 'id')->cascadeOnDelete();

            $table->tinyInteger('day_of_week'); // 0=Chủ nhật, 1=Thứ 2, ..., 6=Thứ 7
            $table->time('start_time');         // Bắt đầu khung giờ cao điểm
            $table->time('end_time');           // Kết thúc
            $table->boolean('is_active')->default(true); // Bật/tắt khung này

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peak_hours');
    }
};
