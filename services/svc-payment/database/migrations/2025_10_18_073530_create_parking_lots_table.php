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
        Schema::create('parking_lots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('gate_pos_x', 18, 15);
            $table->decimal('gate_pos_y', 18, 15);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parking_lots');
    }
};
