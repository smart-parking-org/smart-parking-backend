<?php

namespace Database\Seeders;

use App\Models\PeakHour;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PeakHourSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tạo cấu hình giờ cao điểm cho parking lot 1
        PeakHour::create([
            'key' => 'parking_lot_1_peak_hours',
            'value' => [
                'peak_hours' => [
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '07:00:00',
                        'end_time' => '09:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '07:00:00',
                        'end_time' => '09:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '07:00:00',
                        'end_time' => '09:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '07:00:00',
                        'end_time' => '09:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '07:00:00',
                        'end_time' => '09:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                        'is_active' => true
                    ]
                ]
            ]
        ]);

        // Tạo cấu hình giờ cao điểm cho parking lot 2
        PeakHour::create([
            'key' => 'parking_lot_2_peak_hours',
            'value' => [
                'peak_hours' => [
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '08:00:00',
                        'end_time' => '10:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '18:00:00',
                        'end_time' => '20:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '08:00:00',
                        'end_time' => '10:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '18:00:00',
                        'end_time' => '20:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '08:00:00',
                        'end_time' => '10:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '18:00:00',
                        'end_time' => '20:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '08:00:00',
                        'end_time' => '10:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '18:00:00',
                        'end_time' => '20:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '08:00:00',
                        'end_time' => '10:00:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '18:00:00',
                        'end_time' => '20:00:00',
                        'is_active' => true
                    ]
                ]
            ]
        ]);

        // Tạo cấu hình giờ cao điểm mặc định
        PeakHour::create([
            'key' => 'default_peak_hours',
            'value' => [
                'peak_hours' => [
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '07:30:00',
                        'end_time' => '09:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 1, // Thứ 2
                        'start_time' => '17:30:00',
                        'end_time' => '19:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '07:30:00',
                        'end_time' => '09:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 2, // Thứ 3
                        'start_time' => '17:30:00',
                        'end_time' => '19:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '07:30:00',
                        'end_time' => '09:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 3, // Thứ 4
                        'start_time' => '17:30:00',
                        'end_time' => '19:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '07:30:00',
                        'end_time' => '09:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 4, // Thứ 5
                        'start_time' => '17:30:00',
                        'end_time' => '19:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '07:30:00',
                        'end_time' => '09:30:00',
                        'is_active' => true
                    ],
                    [
                        'day_of_week' => 5, // Thứ 6
                        'start_time' => '17:30:00',
                        'end_time' => '19:30:00',
                        'is_active' => true
                    ]
                ]
            ]
        ]);
    }
}
