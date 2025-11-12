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
        Schema::table('payments', function (Blueprint $table) {
            // Thêm cột reservation_id sau order_id
            $table->foreignId('reservation_id')->nullable()
                ->after('order_id')
                ->constrained('reservations', 'id')
                ->nullOnDelete();

            // Thêm index để query nhanh hơn
            $table->index('reservation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Xóa foreign key constraint
            $table->dropForeign(['reservation_id']);
            // Xóa index
            $table->dropIndex(['reservation_id']);
            // Xóa cột
            $table->dropColumn('reservation_id');
        });
    }
};