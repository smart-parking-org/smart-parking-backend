<?php

namespace Database\Seeders;

use App\Models\PricingRule;
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
        $lotId = DB::table('parking_lots')->value('id');

        $pricingData = [
            'motorbike' => ['hourly' => 5000, 'daily_cap' => 50000, 'monthly_pass' => 300000],
            'car_4_seat' => ['hourly' => 10000, 'daily_cap' => 100000, 'monthly_pass' => 600000],
            'car_7_seat' => ['hourly' => 15000, 'daily_cap' => 150000, 'monthly_pass' => 900000],
            'light_truck' => ['hourly' => 20000, 'daily_cap' => 200000, 'monthly_pass' => 1200000]
        ];

        foreach ($pricingData as $vehicleType => $pricing) {
            PricingRule::create([
                'parking_lot_id' => $lotId,
                'vehicle_type' => $vehicleType,
                'hourly' => $pricing['hourly'],
                'rounding_minutes' => 30,
                'daily_cap' => $pricing['daily_cap'],
                'monthly_pass' => $pricing['monthly_pass'],
                'peak_enabled' => true,
                'peak_multiplier' => 1.5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
