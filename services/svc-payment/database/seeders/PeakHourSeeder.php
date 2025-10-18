<?php

namespace Database\Seeders;

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

        // Mặc định: giờ cao điểm buổi sáng và buổi chiều
        $morningStart = '07:00:00';
        $morningEnd = '09:00:00';
        $eveningStart = '17:00:00';
        $eveningEnd = '19:00:00';

        $records = [];

        // Tạo cho cả 7 ngày trong tuần (0=CN, 1=T2, ..., 6=T7)
        foreach (range(0, 6) as $day) {
            // Buổi sáng
            $records[] = [
                'parking_lot_id' => 1,
                'day_of_week' => $day,
                'start_time' => $morningStart,
                'end_time' => $morningEnd,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Buổi chiều
            $records[] = [
                'parking_lot_id' => 1,
                'day_of_week' => $day,
                'start_time' => $eveningStart,
                'end_time' => $eveningEnd,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('peak_hours')->insert($records);
    }
}
