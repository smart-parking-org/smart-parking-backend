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
        Schema::create('monthly_passes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('parking_lot_id');
            $table->unsignedInteger('months')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('amount'); // VND
            $table->string('status')->default('PENDING'); // PENDING|ACTIVE|CANCELLED|EXPIRED|FAILED
            $table->string('order_id')->unique(); // Liên kết Payment.order_id (vd: MP-20251029-xxxxx)
            $table->string('txn_ref')->nullable()->unique(); // Liên kết Payment.txn_ref
            $table->json('user_snapshot')->nullable();    // dữ liệu cache từ svc-auth
            $table->json('vehicle_snapshot')->nullable(); // dữ liệu cache từ svc-auth
            $table->timestamps();

            $table->index(['user_id', 'vehicle_id', 'parking_lot_id']);
            $table->index(['status']);
            $table->index(['order_id']);
            $table->index(['txn_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_passes');
    }
};

