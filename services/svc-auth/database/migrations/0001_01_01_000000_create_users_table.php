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

            $table->string('name', 100);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable()->unique();

            $table->string('apartment_code', 50)->nullable();

            $table->string('cccd_hash', 100)->unique();
            $table->string('cccd_masked', 20)->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('password');
            $table->enum('role', ['resident', 'admin'])->default('resident');
            $table->boolean('is_active')->default(true);

            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('apartment_code');
            $table->index('role');
            $table->index('is_approved');
            $table->index('is_active');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
