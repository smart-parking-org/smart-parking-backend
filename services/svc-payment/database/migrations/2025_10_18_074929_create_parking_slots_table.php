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
        Schema::create('parking_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained('parking_lots', 'id')->cascadeOnDelete();
            $table->string('slot_code')->unique();
            $table->enum('vehicle_type', ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck']);
            $table->enum('status', ['available', 'occupied'])->default('available');
            $table->decimal('position_x', 18, 15);
            $table->decimal('position_y', 18, 15);
            $table->decimal('distance_from_gate', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parking_slots');
    }
};
