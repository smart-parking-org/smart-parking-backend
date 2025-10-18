<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReservationRequestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $lotId = DB::table('parking_lots')->value('id');
        if (!$lotId)
            return;

        $requests = [];
        for ($i = 1; $i <= 300; $i++) {
            $r = mt_rand(1, 100);
            // Phân bố tỉ lệ thực tế
            // 55% xe máy, 25% ô tô 4 chỗ, 10% ô tô 7 chỗ, 10% xe tải nhẹ
            $vt = match (true) {
                $r <= 55 => 'motorbike',
                $r <= 80 => 'car_4_seat',
                $r <= 90 => 'car_7_seat',
                default => 'light_truck',
            };

            $requests[] = [
                'parking_lot_id' => $lotId,
                'vehicle_type' => $vt,
                'status' => 'pending',
                'processed_at' => null,
                'created_at' => now()->subSeconds(300 - $i),
                'updated_at' => now()->subSeconds(300 - $i),
            ];
        }

        foreach (array_chunk($requests, 200) as $chunk) {
            DB::table('reservation_requests')->insert($chunk);
        }
    }
}
