<?php

namespace Database\Seeders;

use App\Models\PricingRule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PricingRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tạo cấu hình bảng giá cho parking lot 1 - motorbike
        PricingRule::create([
            'key' => 'parking_lot_1_motorbike',
            'value' => [
                'hourly' => 5000,
                'daily_cap' => 50000,
                'monthly_pass' => 300000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.5
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 1 - car_4_seat
        PricingRule::create([
            'key' => 'parking_lot_1_car_4_seat',
            'value' => [
                'hourly' => 10000,
                'daily_cap' => 100000,
                'monthly_pass' => 600000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.5
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 1 - car_7_seat
        PricingRule::create([
            'key' => 'parking_lot_1_car_7_seat',
            'value' => [
                'hourly' => 15000,
                'daily_cap' => 150000,
                'monthly_pass' => 900000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.5
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 1 - light_truck
        PricingRule::create([
            'key' => 'parking_lot_1_light_truck',
            'value' => [
                'hourly' => 20000,
                'daily_cap' => 200000,
                'monthly_pass' => 1200000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.5
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 2 - motorbike
        PricingRule::create([
            'key' => 'parking_lot_2_motorbike',
            'value' => [
                'hourly' => 4000,
                'daily_cap' => 40000,
                'monthly_pass' => 250000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.8
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 2 - car_4_seat
        PricingRule::create([
            'key' => 'parking_lot_2_car_4_seat',
            'value' => [
                'hourly' => 8000,
                'daily_cap' => 80000,
                'monthly_pass' => 500000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.8
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 2 - car_7_seat
        PricingRule::create([
            'key' => 'parking_lot_2_car_7_seat',
            'value' => [
                'hourly' => 12000,
                'daily_cap' => 120000,
                'monthly_pass' => 750000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.8
            ]
        ]);

        // Tạo cấu hình bảng giá cho parking lot 2 - light_truck
        PricingRule::create([
            'key' => 'parking_lot_2_light_truck',
            'value' => [
                'hourly' => 16000,
                'daily_cap' => 160000,
                'monthly_pass' => 1000000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.8
            ]
        ]);

        // Tạo cấu hình bảng giá mặc định - motorbike
        PricingRule::create([
            'key' => 'default_motorbike',
            'value' => [
                'hourly' => 3000,
                'daily_cap' => 30000,
                'monthly_pass' => 200000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.2
            ]
        ]);

        // Tạo cấu hình bảng giá mặc định - car_4_seat
        PricingRule::create([
            'key' => 'default_car_4_seat',
            'value' => [
                'hourly' => 6000,
                'daily_cap' => 60000,
                'monthly_pass' => 400000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.2
            ]
        ]);

        // Tạo cấu hình bảng giá mặc định - car_7_seat
        PricingRule::create([
            'key' => 'default_car_7_seat',
            'value' => [
                'hourly' => 9000,
                'daily_cap' => 90000,
                'monthly_pass' => 600000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.2
            ]
        ]);

        // Tạo cấu hình bảng giá mặc định - light_truck
        PricingRule::create([
            'key' => 'default_light_truck',
            'value' => [
                'hourly' => 12000,
                'daily_cap' => 120000,
                'monthly_pass' => 800000,
                'peak_enabled' => true,
                'peak_multiplier' => 1.2
            ]
        ]);
    }
}
