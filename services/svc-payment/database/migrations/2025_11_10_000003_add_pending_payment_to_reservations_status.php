<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL/MariaDB: Alter enum để thêm pending_payment
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('confirmed', 'checked_in', 'pending_payment', 'pending_checkout', 'checked_out', 'cancelled', 'expired')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Xóa pending_payment khỏi enum
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('confirmed', 'checked_in', 'pending_checkout', 'checked_out', 'cancelled', 'expired')");
    }
};

