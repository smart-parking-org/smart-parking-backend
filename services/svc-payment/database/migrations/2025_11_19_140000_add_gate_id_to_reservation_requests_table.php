<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->foreignId('gate_id')->nullable()->after('parking_lot_id')->constrained('gates')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->dropForeign(['gate_id']);
            $table->dropColumn('gate_id');
        });
    }
};


