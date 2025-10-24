<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParkingLotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('parking_lots')->insert([
            [
                'name' => 'B1 Basement',
                'gate_pos_x' => 10.806176400733412,
                'gate_pos_y' => 106.6286676510779,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
