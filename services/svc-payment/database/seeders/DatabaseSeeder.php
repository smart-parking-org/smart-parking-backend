<?php

namespace Database\Seeders;

use App\Models\User;
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
        DB::table('parking_lots')->truncate();
        DB::table('parking_slots')->truncate();
        DB::table('reservation_requests')->truncate();
        DB::table('pricing_rules')->truncate();
        DB::table('peak_hours')->truncate();
        Schema::enableForeignKeyConstraints();

        $this->call([
            ParkingLotSeeder::class,
            ParkingSlotSeeder::class,
            PricingRuleSeeder::class,
            PeakHourSeeder::class,
            ReservationRequestSeeder::class,
        ]);
    }
}
