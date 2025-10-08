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
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100); // Xe máy, Ô tô 4 chỗ, Ô tô 7 chỗ, Xe tải nhẹ
            $table->string('code', 20)->unique(); // MOTORBIKE, CAR_4, CAR_7, TRUCK
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
