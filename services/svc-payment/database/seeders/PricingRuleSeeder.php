<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('pricing_rules')->insert([
            [
                'parking_lot_id' => 1,
                'vehicle_type' => 'motorbike',
                'hourly' => 2000,
                'rounding_minutes' => 30,
                'daily_cap' => 15000,
                'monthly_pass' => 100000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.25,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parking_lot_id' => 1,
                'vehicle_type' => 'car_4_seat',
                'hourly' => 5000,
                'rounding_minutes' => 30,
                'daily_cap' => 40000,
                'monthly_pass' => 700000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parking_lot_id' => 1,
                'vehicle_type' => 'car_7_seat',
                'hourly' => 7000,
                'rounding_minutes' => 30,
                'daily_cap' => 50000,
                'monthly_pass' => 800000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.6,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parking_lot_id' => 1,
                'vehicle_type' => 'light_truck',
                'hourly' => 10000,
                'rounding_minutes' => 30,
                'daily_cap' => 70000,
                'monthly_pass' => null, // không áp dụng vé tháng cho xe tải
                'peak_enabled' => true,
                'peak_multiplier' => 2.0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
