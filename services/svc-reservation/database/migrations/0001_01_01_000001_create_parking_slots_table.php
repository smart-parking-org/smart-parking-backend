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
            $table->foreignId("zone_id")->constrained("parking_zones", "id");
            $table->string('code', 50);
            $table->unique(['zone_id', 'code']);
            $table->string('vehicle_type', 20); //lấy từ code của svc-auth
            $table->enum('status', [
                'available',
                'hold',
                'reserved',
                'occupied',
                'maintenance',
                'offline'
            ])->default('available');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['zone_id', 'status']);
            $table->index(['zone_id', 'vehicle_type']);
            $table->index(['zone_id', 'status', 'vehicle_type']);
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
