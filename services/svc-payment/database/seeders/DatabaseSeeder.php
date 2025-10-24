<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('parking_slots')->truncate();
        DB::table('reservations')->truncate();
        DB::table('reservation_requests')->truncate();
        DB::table('pricing_rules')->truncate();
        DB::table('peak_hours')->truncate();
        DB::table('extension_policies')->truncate();
        DB::table('parking_lots')->truncate();
        Schema::enableForeignKeyConstraints();

        DB::beginTransaction();
        try {
            $this->call([
                ParkingLotSeeder::class,
                ParkingSlotSeeder::class,
                PricingRuleSeeder::class,
                PeakHourSeeder::class,
                ExtensionPolicySeeder::class,
            ]);

            DB::commit();
            $this->command->info('✅ Seeding hoàn tất (đã COMMIT).');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding lỗi: ' . $e->getMessage());
            $this->command->warn('Đã ROLLBACK, dữ liệu vẫn đang ở trạng thái trống sau khi truncate.');
        }
    }
}
