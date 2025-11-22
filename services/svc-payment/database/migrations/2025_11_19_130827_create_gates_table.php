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
        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained()->onDelete('cascade');
            $table->string('gate_code')->unique();
            $table->enum('gate_type', ['entrance', 'exit', 'both'])->default('both');
            $table->decimal('position_x', 18, 15)->nullable();
            $table->decimal('position_y', 18, 15)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gates');
    }
};
