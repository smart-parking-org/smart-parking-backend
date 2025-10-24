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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('phone', 20);
            $table->string('password', 255);
            $table->enum('role', ['resident', 'admin', 'staff'])->default('resident');

            $table->boolean('is_active')->default(true);
            $table->text('fcm_token')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
