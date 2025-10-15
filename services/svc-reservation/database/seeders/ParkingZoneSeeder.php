<?php

namespace Database\Seeders;

use App\Models\ParkingZone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ParkingZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['code' => 'B1', 'name' => 'Basement 1', 'capacity' => 120, 'is_active' => true],
            ['code' => 'B2', 'name' => 'Basement 2', 'capacity' => 100, 'is_active' => true],
            ['code' => 'A', 'name' => 'Zone A', 'capacity' => 60, 'is_active' => true],
        ];
        foreach ($data as $row) {
            ParkingZone::updateOrCreate(['code' => $row['code']], $row);
        }
    }
}
