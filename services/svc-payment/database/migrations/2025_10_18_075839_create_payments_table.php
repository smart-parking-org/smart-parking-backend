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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->unsignedInteger('amount');
            $table->string('txn_ref')->unique();
            $table->string('status')->default('PENDING'); // PENDING|PAID|FAILED
            $table->string('vnp_response_code')->nullable();
            $table->string('vnp_transaction_no')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('card_type')->nullable();
            $table->json('meta')->nullable();
            // Thêm foreign key để liên kết với reservation
            $table->foreignId('reservation_id')->nullable()
                ->constrained('reservations', 'id')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['reservation_id', 'status']);
        });
        ;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
