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
        Schema::create('checkout_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations', 'id')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments', 'id')->nullOnDelete();
            $table->string('checkout_code', 50)->unique(); // Mã QR checkout
            $table->enum('status', ['active', 'used', 'expired'])->default('active');
            $table->timestamp('expires_at')->nullable(); // Thời gian hết hạn QR code
            $table->timestamp('used_at')->nullable(); // Thời gian sử dụng QR code
            $table->timestamps();

            // Index để tìm nhanh
            $table->index('checkout_code');
            $table->index('reservation_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_codes');
    }
};

