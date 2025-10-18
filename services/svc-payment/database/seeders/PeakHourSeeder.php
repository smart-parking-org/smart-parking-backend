<?php

namespace Database\Seeders;

use App\Models\PeakHour;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PeakHourSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $lotId = DB::table('parking_lots')->value('id');

        // Tạo peak hours (7-9h và 17-19h các ngày trong tuần)
        $peakHours = [
            ['day_of_week' => 1, 'start_time' => '07:00:00', 'end_time' => '09:00:00'],
            ['day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '19:00:00'],
            ['day_of_week' => 2, 'start_time' => '07:00:00', 'end_time' => '09:00:00'],
            ['day_of_week' => 2, 'start_time' => '17:00:00', 'end_time' => '19:00:00'],
            ['day_of_week' => 3, 'start_time' => '07:00:00', 'end_time' => '09:00:00'],
            ['day_of_week' => 3, 'start_time' => '17:00:00', 'end_time' => '19:00:00'],
            ['day_of_week' => 4, 'start_time' => '07:00:00', 'end_time' => '09:00:00'],
            ['day_of_week' => 4, 'start_time' => '17:00:00', 'end_time' => '19:00:00'],
            ['day_of_week' => 5, 'start_time' => '07:00:00', 'end_time' => '09:00:00'],
            ['day_of_week' => 5, 'start_time' => '17:00:00', 'end_time' => '19:00:00'],
        ];

        foreach ($peakHours as $peakHour) {
            PeakHour::create([
                'parking_lot_id' => $lotId,
                'day_of_week' => $peakHour['day_of_week'],
                'start_time' => $peakHour['start_time'],
                'end_time' => $peakHour['end_time'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
