<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Services\HungarianSlotAllocationService;
use App\Services\PriorityQueueSlotAllocationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

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

        // Reset dữ liệu
        $this->resetData();

        // Tạo dữ liệu test
        $this->createTestData($totalRequests, $peakRequests, $normalRequests);

        $this->info("✅ Hoàn thành tạo dữ liệu mô phỏng!");

        // Chạy test allocation
        $this->runTest($algorithmNameMap, $algorithm);
    }

    private function resetData()
    {
        Schema::disableForeignKeyConstraints();
        Reservation::truncate();
        ReservationRequest::truncate();
        Schema::enableForeignKeyConstraints();
        ParkingSlot::query()->update(['status' => 'available']);
    }

    private function createTestData($totalRequests, $peakRequests, $normalRequests)
    {
        $this->info("📈 Tạo {$peakRequests} requests giờ cao điểm...");
        $this->createPeakHourRequests($peakRequests);

        $this->info("📊 Tạo {$normalRequests} requests giờ bình thường...");
        $this->createNormalHourRequests($normalRequests);
    }

    private function createPeakHourRequests($count)
    {
        // Giờ cao điểm: 7-9h và 17-19h
        $peakHours = [
            ['start' => '07:00', 'end' => '09:00'],
            ['start' => '17:00', 'end' => '19:00']
        ];

        // Phân bố loại xe theo thực tế Việt Nam
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $vehicleRatios = [0.70, 0.25, 0.04, 0.01];

        for ($i = 0; $i < $count; $i++) {
            $peakHour = $peakHours[array_rand($peakHours)];
            $startTime = $this->randomTimeInRange($peakHour['start'], $peakHour['end']);
            $vehicleType = $this->selectVehicleType($vehicleTypes, $vehicleRatios);

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => rand(60, 480), // 1-8 giờ
                'status' => 'pending',
                'requested_at' => now()
            ]);
        }
    }

    private function createNormalHourRequests($count)
    {
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $vehicleRatios = [0.70, 0.25, 0.04, 0.01];

        for ($i = 0; $i < $count; $i++) {
            $startTime = $this->randomNormalHour();
            $vehicleType = $this->selectVehicleType($vehicleTypes, $vehicleRatios);

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => rand(30, 360), // 30 phút - 6 giờ
                'status' => 'pending',
                'requested_at' => now()
            ]);
        }
    }

    private function selectVehicleType($types, $ratios)
    {
        $random = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        for ($i = 0; $i < count($types); $i++) {
            $cumulative += $ratios[$i];
            if ($random <= $cumulative) {
                return $types[$i];
            }
        }

        return $types[0];
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
        $hour = rand(0, 23);

        // Tránh giờ cao điểm
        while (($hour >= 7 && $hour < 9) || ($hour >= 17 && $hour < 19)) {
            $hour = rand(0, 23);
        }

        return Carbon::now()->setHour($hour)->setMinute(rand(0, 59))->setSecond(0);
    }

    private function getRandomParkingLot()
    {
        $lot = ParkingLot::inRandomOrder()->first();
        return $lot ? $lot->id : 1;
    }

    private function runTest($algorithmNameMap, $algorithm)
    {
        $this->info("\n🔧 Bắt đầu test thuật toán " . $algorithmNameMap[$algorithm] . "...");

        // ƯU TIÊN THEO THỜI GIAN ĐẶT
        $requests = ReservationRequest::where('status', 'pending')
            ->orderBy('requested_at', 'asc') // Sắp xếp theo thời gian đặt
            ->get();

        $totalRequests = $requests->count();
        $successCount = 0;
        $conflictCount = 0;
        $totalProcessingTime = 0;
        $processingTimes = [];

        if ($algorithm === 'priority_queue') {
            foreach ($requests as $request) {
                $allocatedSlot = PriorityQueueSlotAllocationService::allocateSlot($request);

                $processingTime = $request->processing_time_ms ?? 0;
                $totalProcessingTime += $processingTime;
                $processingTimes[] = $processingTime;

                if ($allocatedSlot) {
                    $successCount++;
                    $this->createReservation($request, $allocatedSlot);
                } else {
                    $conflictCount++;
                    $request->update(['status' => 'failed']);
                }
            }
        } else {
            //  Hungarian - xử lý batch
            $result = HungarianSlotAllocationService::allocateBatch($requests);

            $successCount = $result['stats']['success'];
            $conflictCount = $result['stats']['failed'];
            $totalProcessingTime = $result['stats']['total_processing_time'];

            // Tạo reservations cho các allocation thành công
            foreach ($result['allocations'] as $allocation) {
                $request = ReservationRequest::find($allocation['request_id']);
                $slot = ParkingSlot::find($allocation['slot_id']);

                if ($request && $slot) {
                    $this->createReservation($request, $slot);
                    $processingTimes[] = $request->processing_time_ms ?? 0;
                }
            }
        }


        // Tính kết quả
        $avgProcessingTime = $totalRequests > 0 ? $totalProcessingTime / $totalRequests : 0;
        $maxProcessingTime = !empty($processingTimes) ? max($processingTimes) : 0;
        $successRate = $totalRequests > 0 ? ($successCount / $totalRequests) * 100 : 0;
        $conflictRate = $totalRequests > 0 ? ($conflictCount / $totalRequests) * 100 : 0;
        $utilizationRate = $this->calculateUtilization();

        // Hiển thị kết quả
        $this->displayResults(
            $totalRequests,
            $successCount,
            $conflictCount,
            $successRate,
            $conflictRate,
            $avgProcessingTime,
            $maxProcessingTime,
            $utilizationRate
        );

        // Đánh giá theo yêu cầu đề tài
        $this->evaluateResults($conflictRate, $avgProcessingTime);
    }

    private function createReservation($request, $slot)
    {
        Reservation::create([
            'user_id' => null,
            'vehicle_id' => null,
            'slot_id' => $slot->id,
            'reservation_request_id' => $request->id,
            'reservation_code' => 'RES-' . strtoupper(uniqid()) . '-' . now()->format('Ymd'),
            'status' => 'confirmed',
            'start_time' => $request->desired_start_time, // Thời gian bắt đầu đỗ,
            'end_time' => $request->desired_start_time->copy()
                ->addMinutes($request->duration_minutes), // Thời gian kết thúc đỗ
            'expires_at' => $request->desired_start_time->copy()
                ->addMinutes(15) // Thời gian hết hạn giữ chỗ
        ]);
    }

    private function displayResults(
        $total,
        $success,
        $conflict,
        $successRate,
        $conflictRate,
        $avgTime,
        $maxTime,
        $utilization
    ) {
        $this->info("\n📊 KẾT QUẢ TEST:");
        $this->info("═══════════════════════════════════════");
        $this->info("Tổng requests: {$total}");
        $this->info("Thành công: {$success} ({$successRate}%)");
        $this->info("Thất bại: {$conflict} ({$conflictRate}%)");
        $this->info("───────────────────────────────────────");
        $this->info("Thời gian xử lý trung bình: " . round($avgTime, 2) . "ms");
        $this->info("Thời gian xử lý tối đa: " . round($maxTime, 2) . "ms");
        $this->info("───────────────────────────────────────");
        $this->info("Độ sử dụng chỗ: " . round($utilization, 2) . "%");
        $this->info("═══════════════════════════════════════");
    }

    private function evaluateResults($conflictRate, $avgProcessingTime)
    {
        $this->info("\n🎯 ĐÁNH GIÁ THEO YÊU CẦU ĐỀ TÀI:");

        $conflictPass = $conflictRate < 2;
        $timePass = $avgProcessingTime < 1500; // < 1.5s để đảm bảo < 3s với 300 requests

        if ($conflictPass && $timePass) {
            $this->info("🎉 THUẬT TOÁN ĐẠT YÊU CẦU:");
            $this->info("   ✅ Xung đột: {$conflictRate}% < 2%");
            $this->info("   ✅ Thời gian: {$avgProcessingTime}ms < 1500ms");
            $this->info("   ✅ Đáp ứng < 3s với 300 lượt giả lập");
        } else {
            $this->warn("⚠️ THUẬT TOÁN CHƯA ĐẠT YÊU CẦU:");
            if (!$conflictPass) {
                $this->warn("   ❌ Xung đột: {$conflictRate}% >= 2%");
            }
            if (!$timePass) {
                $this->warn("   ❌ Thời gian: {$avgProcessingTime}ms >= 1500ms");
            }
        }

        return $conflictPass && $timePass;
    }

    private function calculateUtilization()
    {
        $totalSlots = ParkingSlot::count();
        $usedSlots = Reservation::whereIn('status', ['confirmed', 'checked_in'])->distinct('slot_id')->count();

        return $totalSlots > 0 ? ($usedSlots / $totalSlots) * 100 : 0;
    }
}
