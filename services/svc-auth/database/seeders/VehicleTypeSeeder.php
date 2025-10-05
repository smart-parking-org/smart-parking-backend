<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vehicle_types = [
            [
                'type_name' => 'Xe máy',
                'description' => 'Xe máy, xe tay ga',
                'hourly_rate' => 5000,
                'daily_rate' => 50000,
                'monthly_rate' => 300000
            ],
            [
                'type_name' => 'Ô tô 4 chỗ',
                'description' => 'Sedan, hatchback',
                'hourly_rate' => 15000,
                'daily_rate' => 150000,
                'monthly_rate' => 1200000
            ],
            [
                'type_name' => 'Ô tô 7 chỗ',
                'description' => 'SUV, MPV',
                'hourly_rate' => 20000,
                'daily_rate' => 200000,
                'monthly_rate' => 1500000
            ],
            [
                'type_name' => 'Xe tải nhẹ',
                'description' => 'Xe bán tải, xe tải dưới 2 tấn',
                'hourly_rate' => 25000,
                'daily_rate' => 250000,
                'monthly_rate' => 1800000
            ],
        ];

        foreach ($vehicle_types as $vehicle_type) {
            VehicleType::create([
                'type_name' => $vehicle_type['type_name'],
                'description' => $vehicle_type['description'],
                'hourly_rate' => $vehicle_type['hourly_rate'],
                'daily_rate' => $vehicle_type['daily_rate'],
                'monthly_rate' => $vehicle_type['monthly_rate'],
                'is_active' => true
            ]);
        }
    }
}
