<?php

namespace Database\Seeders;

use App\Models\PeakHour;
use Carbon\Carbon;
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
            ['day_of_week' => 1, 'start_time' => '07:00:00', 'end_time' => '09:00:00'], // Thứ 2
            ['day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '19:00:00'], // Thứ 2
            ['day_of_week' => 2, 'start_time' => '07:00:00', 'end_time' => '09:00:00'], // Thứ 3
            ['day_of_week' => 2, 'start_time' => '17:00:00', 'end_time' => '19:00:00'], // Thứ 3
            ['day_of_week' => 3, 'start_time' => '07:00:00', 'end_time' => '09:00:00'], // Thứ 4
            ['day_of_week' => 3, 'start_time' => '17:00:00', 'end_time' => '19:00:00'], // Thứ 4
            ['day_of_week' => 4, 'start_time' => '07:00:00', 'end_time' => '09:00:00'], // Thứ 5
            ['day_of_week' => 4, 'start_time' => '17:00:00', 'end_time' => '19:00:00'], // Thứ 5
            ['day_of_week' => 5, 'start_time' => '07:00:00', 'end_time' => '09:00:00'], // Thứ 6
            ['day_of_week' => 5, 'start_time' => '17:00:00', 'end_time' => '19:00:00'], // Thứ 6
        ];

        foreach ($peakHours as $index => $peakHour) {
            try {
                // Convert local time sang UTC để lưu vào DB
                $startTimeUTC = $this->convertLocalTimeToUTC($peakHour['start_time']);
                $endTimeUTC = $this->convertLocalTimeToUTC($peakHour['end_time']);

                PeakHour::create([
                    'parking_lot_id' => $lotId,
                    'day_of_week' => $peakHour['day_of_week'],
                    'start_time' => $startTimeUTC,
                    'end_time' => $endTimeUTC,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Exception $e) {
                $this->command->error("✗ Lỗi tạo peak hour thứ " . ($index + 1) . ": " . $e->getMessage());
            }
        }
    }

    /**
     * Convert local time sang UTC
     */
    private function convertLocalTimeToUTC($localTime)
    {
        // Tạo datetime với local timezone (Asia/Ho_Chi_Minh)
        $today = Carbon::today('Asia/Ho_Chi_Minh');
        $time = Carbon::createFromFormat('H:i:s', $localTime, 'Asia/Ho_Chi_Minh');
        $datetime = $today->setTime($time->hour, $time->minute, $time->second);

        return $datetime->utc();
    }
}
