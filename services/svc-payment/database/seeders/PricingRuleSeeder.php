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
            'motorbike' => ['hourly' => 3000, 'daily_cap' => 10000, 'monthly_pass' => 150000],
            'car_4_seat' => ['hourly' => 15000, 'daily_cap' => 80000, 'monthly_pass' => 1800000],
            'car_7_seat' => ['hourly' => 18000, 'daily_cap' => 100000, 'monthly_pass' => 2200000],
            'light_truck' => ['hourly' => 20000, 'daily_cap' => 120000, 'monthly_pass' => 2500000]
        ];

        foreach ($pricingData as $vehicleType => $pricing) {
            PricingRule::create([
                'parking_lot_id' => $lotId,
                'vehicle_type' => $vehicleType,
                'hourly' => $pricing['hourly'],
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
