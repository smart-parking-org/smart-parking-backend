<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slot_gate_distances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('parking_slots')->onDelete('cascade');
            $table->foreignId('gate_id')->constrained('gates')->onDelete('cascade');
            $table->decimal('distance', 10, 2)->comment('Khoảng cách từ slot đến cổng (mét)');
            $table->timestamps();
            
            $table->unique(['slot_id', 'gate_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slot_gate_distances');
    }
};
