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
        $types = [
            ['name' => 'Xe máy', 'code' => 'motorbike', 'description' => 'Dành cho xe máy thông thường'],
            ['name' => 'Ô tô 4 chỗ', 'code' => 'car_4', 'description' => 'Ô tô 4 chỗ ngồi'],
            ['name' => 'Ô tô 7 chỗ', 'code' => 'car_7', 'description' => 'Ô tô 7 chỗ ngồi'],
            ['name' => 'Xe tải nhẹ', 'code' => 'truck', 'description' => 'Xe tải dưới 2 tấn'],
        ];

        foreach ($types as $type) {
            VehicleType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
