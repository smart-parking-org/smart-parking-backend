<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL/MariaDB: Alter enum để thêm pending_checkout
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('confirmed', 'checked_in', 'pending_checkout', 'checked_out', 'cancelled', 'expired')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Xóa pending_checkout khỏi enum (chuyển về enum cũ)
        DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM('confirmed', 'checked_in', 'checked_out', 'cancelled', 'expired')");
    }
};