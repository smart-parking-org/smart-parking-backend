<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Services\SlotAllocationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GeneratePeakHourReservations extends Command
{
    protected $signature = 'test:peak-hour-reservations
                            {--requests=300 : Tổng số requests}
                            {--peak-ratio=60 : Tỷ lệ giờ cao điểm (%)}
                            {--algorithm=priority_queue : Thuật toán sử dụng}';
    protected $description = 'Tạo dữ liệu mô phỏng với phân bố giờ cao điểm';

    public function handle()
    {
        $totalRequests = (int) $this->option('requests');
        $peakRatio = (int) $this->option('peak-ratio');
        $algorithm = $this->option('algorithm');

        $peakRequests = intval($totalRequests * $peakRatio / 100);
        $normalRequests = $totalRequests - $peakRequests;

        $algorithmNameMap = [
            'priority_queue' => 'Priority Queue',
            'hungarian' => 'Hungarian',
        ];

        $this->info("🚗 Tạo {$totalRequests} requests với phân bố:");
        $this->info("   - Giờ cao điểm: {$peakRequests} requests ({$peakRatio}%)");
        $this->info("   - Giờ bình thường: {$normalRequests} requests (" . (100 - $peakRatio) . "%)");
        $this->info("   - Thuật toán: " . $algorithmNameMap[$algorithm]);

        // Reset dữ liệu cũ
        Schema::disableForeignKeyConstraints();
        Reservation::truncate();
        ReservationRequest::truncate();
        Schema::enableForeignKeyConstraints();
        ParkingSlot::query()->update(['status' => 'available']);


        // Tạo requests giờ cao điểm
        $this->generatePeakHourRequests($peakRequests);

        // Tạo requests giờ bình thường
        $this->generateNormalHourRequests($normalRequests);

        $this->info("✅ Hoàn thành tạo dữ liệu mô phỏng!");

        // Chạy test allocation
        $this->runAllocationTest($algorithmNameMap, $algorithm);
    }

    private function generatePeakHourRequests($count)
    {
        $this->info("📈 Tạo {$count} requests giờ cao điểm...");

        $peakHours = [
            ['start' => '07:00', 'end' => '09:00'], // Sáng
            ['start' => '17:00', 'end' => '19:00'], // Chiều
        ];

        // Phân bổ theo tỷ lệ thực tế Việt Nam
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $vehicleRatios = [0.70, 0.25, 0.04, 0.01]; // 70%, 25%, 4%, 1%

        for ($i = 0; $i < $count; $i++) {
            $peakHour = $peakHours[array_rand($peakHours)];
            $startTime = $this->randomTimeInRange($peakHour['start'], $peakHour['end']);
            $duration = rand(60, 480); // 1-8 giờ

            // Chọn loại xe theo tỷ lệ
            $vehicleType = $this->selectVehicleTypeByRatio($vehicleTypes, $vehicleRatios);

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        }
    }

    private function generateNormalHourRequests($count)
    {
        $this->info("📊 Tạo {$count} requests giờ bình thường...");

        // Phân bổ theo tỷ lệ thực tế Việt Nam
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $vehicleRatios = [0.70, 0.25, 0.04, 0.01]; // 70%, 25%, 4%, 1%

        for ($i = 0; $i < $count; $i++) {
            // Tạo thời gian ngẫu nhiên ngoài giờ cao điểm
            $startTime = $this->randomNormalHour();
            $duration = rand(30, 360); // 30 phút đến 6 giờ

            // Chọn loại xe theo tỷ lệ
            $vehicleType = $this->selectVehicleTypeByRatio($vehicleTypes, $vehicleRatios);

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        }
    }

    private function selectVehicleTypeByRatio($vehicleTypes, $ratios)
    {
        $random = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        for ($i = 0; $i < count($vehicleTypes); $i++) {
            $cumulative += $ratios[$i];
            if ($random <= $cumulative) {
                return $vehicleTypes[$i];
            }
        }

        return $vehicleTypes[0]; // fallback
    }

    private function randomTimeInRange($startTime, $endTime)
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $randomMinutes = rand(0, $start->diffInMinutes($end));

        return $start->copy()->addMinutes($randomMinutes);
    }

    private function randomNormalHour()
    {
        // Tạo thời gian ngẫu nhiên ngoài giờ cao điểm (7-9h, 17-19h)
        $hour = rand(0, 23);

        // Tránh giờ cao điểm
        while (($hour >= 7 && $hour < 9) || ($hour >= 17 && $hour < 19)) {
            $hour = rand(0, 23);
        }

        $minute = rand(0, 59);

        return Carbon::now()->setHour($hour)->setMinute($minute)->setSecond(0);
    }

    private function getRandomParkingLot()
    {
        $parkingLot = ParkingLot::inRandomOrder()->first();
        return $parkingLot ? $parkingLot->id : 1;
    }

    private function runAllocationTest($algorithmNameMap, $algorithm)
    {
        $this->info("\n🔧 Chạy test allocation với thuật toán " . $algorithmNameMap[$algorithm] . " ...");

        $slotAllocationService = app(SlotAllocationService::class);
        $requests = ReservationRequest::where('status', 'pending')->get();

        $successCount = 0;
        $conflicts = 0;
        $totalProcessingTime = 0;
        $slotUtilization = [];

        foreach ($requests as $request) {
            $allocatedSlot = match ($algorithm) {
                'hungarian' => $slotAllocationService->allocateSlotWithHungarian($request),
                default => $slotAllocationService->allocateSlotWithPriorityQueue($request)
            };

            $processingTime = $request->processing_time_ms ?? 0;
            $totalProcessingTime += $processingTime;

            if ($allocatedSlot) {
                $successCount++;
                $request->update(['status' => 'assigned']);
                $endTime = $request->desired_start_time->copy()->addMinutes($request->duration_minutes);
                // Track slot utilization
                $slotUtilization[$allocatedSlot->id][] = [
                    'start' => $request->desired_start_time,
                    'end' => $endTime,
                ];

                Reservation::create([
                    'user_id' => null,
                    'vehicle_id' => null,
                    'slot_id' => $allocatedSlot->id,
                    'reservation_request_id' => $request->id,
                    'reservation_code' => 'RES-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd'),
                    'status' => 'confirmed',
                    'reserved_at' => now(),
                    'expires_at' => now()->addMinutes(15),
                    'user_snapshot' => null,
                    'vehicle_snapshot' => null,
                    'pricing_snapshot' => null
                ]);
            } else {
                $conflicts++;
                $request->update(['status' => 'failed']);
            }
        }

        // Tính metrics
        $totalRequests = $requests->count();
        $avgProcessingTime = $totalProcessingTime / $totalRequests;
        $successRate = ($successCount / $totalRequests) * 100;
        $conflictRate = ($conflicts / $totalRequests) * 100;
        $utilizationRate = $this->calculateSlotUtilization($slotUtilization);

        // Hiển thị kết quả
        $this->info("\n📊 KẾT QUẢ MÔ PHỎNG:");
        $this->info("Thuật toán: " . $algorithmNameMap[$algorithm]);
        $this->info("Tổng requests: {$totalRequests}");
        $this->info("Tỷ lệ thành công: " . round($successRate, 2) . "% ({$successCount}/{$totalRequests})");
        $this->info("Tỷ lệ xung đột: " . round($conflictRate, 2) . "% ({$conflicts}/{$totalRequests})");
        $this->info("Thời gian xử lý trung bình: " . round($avgProcessingTime, 2) . "ms");
        $this->info("Độ sử dụng chỗ: " . round($utilizationRate, 2) . "%");

        // Đánh giá theo yêu cầu đề tài
        $this->evaluateResults($conflictRate, $avgProcessingTime, $algorithm, $algorithmNameMap);
    }

    private function evaluateResults($conflictRate, $avgProcessingTime, $algorithm, $algorithmNameMap)
    {
        $this->info("\n🎯 ĐÁNH GIÁ THEO YÊU CẦU ĐỀ TÀI:");

        $conflictPass = $conflictRate < 2;
        $timePass = $avgProcessingTime < 1500;

        if ($conflictPass && $timePass) {
            $this->info("🎉 THUẬT TOÁN " . $algorithmNameMap[$algorithm] . " ĐẠT YÊU CẦU:");
            $this->info("   ✅ Xung đột: {$conflictRate}% < 2%");
            $this->info("   ✅ Thời gian: {$avgProcessingTime}ms < 1500ms");
        } else {
            $this->warn("⚠️ THUẬT TOÁN " . $algorithmNameMap[$algorithm] . " CHƯA ĐẠT YÊU CẦU:");
            if (!$conflictPass) {
                $this->warn("   ❌ Xung đột: {$conflictRate}% >= 2%");
            }
            if (!$timePass) {
                $this->warn("   ❌ Thời gian: {$avgProcessingTime}ms >= 1500ms");
            }
        }

        return $conflictPass && $timePass;
    }

    private function calculateSlotUtilization($slotUtilization)
    {
        $totalSlots = ParkingSlot::count();
        $usedSlots = count($slotUtilization);

        return ($usedSlots / $totalSlots) * 100;
    }
}
