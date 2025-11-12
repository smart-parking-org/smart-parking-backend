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
        Schema::table('reservations', function (Blueprint $table) {
            // Thêm cột payment_id sau reservation_code
            $table->foreignId('payment_id')->nullable()
                ->after('reservation_code')
                ->constrained('payments', 'id')
                ->nullOnDelete();

            // Thêm index để query nhanh hơn
            $table->index('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Xóa foreign key constraint
            $table->dropForeign(['payment_id']);
            // Xóa index
            $table->dropIndex(['payment_id']);
            // Xóa cột
            $table->dropColumn('payment_id');
        });
    }
};

