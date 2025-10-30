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

/**
 * COMMAND: MÔ PHỎNG VÀ ĐÁNH GIÁ THUẬT TOÁN CẤP CHỖ
 *
 * Mục đích:
 * - Sinh dữ liệu 300 lượt đặt với phân bố giờ cao điểm 60%
 * - Đo tỷ lệ xung đột, thời gian đáp ứng, độ sử dụng chỗ
 * - So sánh 2 thuật toán (Priority Queue vs Hungarian)
 * - Đánh giá theo yêu cầu: xung đột < 2%, thời gian TB < 1.5s
 *
 * Cách sử dụng:
 * php artisan test:peak-hour-reservations --requests=300 --peak-ratio=60 --algorithm=priority_queue
 * php artisan test:peak-hour-reservations --requests=300 --peak-ratio=60 --algorithm=hungarian
 */
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

        // Bước 1: Reset dữ liệu cũ
        $this->resetData();

        // Bước 2: Tạo dữ liệu mô phỏng
        $this->createTestData($peakRequests, $normalRequests);

        $this->info("✅ Hoàn thành tạo dữ liệu mô phỏng!");

        // Bước 3: Chạy test allocation và đo metrics
        $this->runTest($algorithmNameMap, $algorithm);
    }

    /**
     * Reset dữ liệu cũ để đảm bảo test sạch
     */
    private function resetData()
    {
        Schema::disableForeignKeyConstraints();
        Reservation::truncate();
        ReservationRequest::truncate();
        Schema::enableForeignKeyConstraints();
        ParkingSlot::query()->update(['status' => 'available']);
    }


    /**
     * Tạo dữ liệu test
     */
    private function createTestData($peakRequests, $normalRequests)
    {
        $this->info("📈 Tạo {$peakRequests} requests giờ cao điểm...");
        $this->createPeakHourRequests($peakRequests);

        $this->info("📊 Tạo {$normalRequests} requests giờ bình thường...");
        $this->createNormalHourRequests($normalRequests);
    }

    /**
     * Tạo requests cho giờ cao điểm
     *
     * Giờ cao điểm:
     * - Sáng: 07:00 - 09:00 (đi làm)
     * - Chiều: 17:30 - 19:30 (về nhà)
     *
     * Phân bố loại xe theo tỷ lệ thực tế:
     * - Xe máy: 50% (phổ biến nhất trong chung cư)
     * - Ô tô 4 chỗ: 33%
     * - Ô tô 7 chỗ: 12%
     * - Xe tải nhẹ: 5%
     *
     * Duration: 4-8 giờ (phản ánh cả ngày hoặc qua đêm)
     */
    private function createPeakHourRequests($count)
    {
        // Khung giờ cao điểm
        $peakHours = [
            ['start' => '07:00', 'end' => '09:00'],
            ['start' => '17:30', 'end' => '19:30']
        ];

        // Phân bố loại xe theo tỷ lệ thực tế chung cư
        $types = [
            'motorbike' => 0.5,
            'car_4_seat' => 0.33,
            'car_7_seat' => 0.12,
            'light_truck' => 0.05,
        ];

        for ($i = 0; $i < $count; $i++) {
            // Random chọn khung giờ cao điểm (sáng hoặc chiều)
            $peakHour = $peakHours[array_rand($peakHours)];

            // Random thời gian bắt đầu trong khung giờ
            $startTime = $this->randomTimeInRange($peakHour['start'], $peakHour['end']);

            // Random loại xe theo phân bố
            $vehicleType = $this->selectVehicleType($types);

            // Duration: 4-8 giờ (bước nhảy 30 phút)
            // Phản ánh thực tế: đỗ cả ngày (sáng) hoặc qua đêm (chiều)
            $duration = rand(8, 16) * 30; // 240 - 480 phút <=> 4-8 giờ

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => now()
            ]);
        }
    }

    /**
     * Tạo ] requests cho giờ bình thường
     *
     * Giờ bình thường: Tất cả giờ khác ngoài giờ cao điểm
     *
     * Phân bố loại xe: Giống giờ cao điểm (50/33/12/5)
     * Duration: 2-6 giờ (ngắn hơn giờ cao điểm, bước nhảy 30 phút)
     */
    private function createNormalHourRequests($count)
    {
        // Phân bố loại xe giống giờ cao điểm
        $types = [
            'motorbike' => 0.5,
            'car_4_seat' => 0.33,
            'car_7_seat' => 0.12,
            'light_truck' => 0.05,
        ];

        for ($i = 0; $i < $count; $i++) {
            // Random thời gian bắt đầu (tránh giờ cao điểm)
            $startTime = $this->randomNormalHour();

            // Random loại xe theo phân bố
            $vehicleType = $this->selectVehicleType($types);

            // Duration: 2-6 giờ (bước nhảy 30 phút)
            // Ngắn hơn giờ cao điểm vì người dùng đỗ ngắn hơn
            $duration = rand(4, 12) * 30; // 120 - 360 phút <=> 2-6 giờ

            ReservationRequest::create([
                'parking_lot_id' => $this->getRandomParkingLot(),
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => now()
            ]);
        }
    }

    /**
     * Chọn loại xe theo phân bố xác suất (weighted random)
     */
    private function selectVehicleType($weights)
    {
        $random = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ($weights as $k => $w) {
            $cumulative += $w;
            if ($random <= $cumulative) {
                return $k;
            }
        }

        return array_key_first($weights);
    }

    /**
     * Random thời gian trong khung giờ cho trước
     */
    private function randomTimeInRange($startTime, $endTime)
    {
        $today = Carbon::today();
        $start = $today->copy()->setTimeFromTimeString($startTime);
        $end = $today->copy()->setTimeFromTimeString($endTime);

        $randomMinutes = rand(0, max(0, $start->diffInMinutes($end)));
        return $start->copy()->addMinutes($randomMinutes);
    }

    /**
     * Random giờ bình thường (tránh giờ cao điểm)
     */
    private function randomNormalHour()
    {
        $today = Carbon::today();
        $hour = rand(0, 23);

        // Tránh giờ cao điểm: 7-9h sáng và 17-19h chiều
        while (($hour >= 7 && $hour < 9) || ($hour >= 17 && $hour < 19)) {
            $hour = rand(0, 23);
        }
        $result = $today->copy()->setHour($hour)->setMinute(rand(0, 59))->setSecond(0);
        return $result;
    }

    private function getRandomParkingLot()
    {
        $lot = ParkingLot::inRandomOrder()->first();
        return $lot ? $lot->id : 1;
    }

    /**
     * Chạy test allocation và đo metrics
     *
     * Quy trình:
     * 1. Sắp xếp requests theo ưu tiên (desired_start_time, requested_at)
     * 2. Xử lý bằng thuật toán được chọn
     * 3. Đo metrics: tỷ lệ xung đột, thời gian đáp ứng, độ sử dụng chỗ
     * 4. Đánh giá theo yêu cầu đề tài
     */
    private function runTest($algorithmNameMap, $algorithm)
    {
        $this->info("\n🔧 Bắt đầu test thuật toán " . $algorithmNameMap[$algorithm] . "...");

        // ƯU TIÊN THEO THỜI GIAN ĐẶT
        // Sắp xếp theo desired_start_time (sớm nhất trước) để đảm bảo ưu tiên
        // Tie-breaker: requested_at (đặt sớm hơn được ưu tiên)
        $requests = ReservationRequest::where('status', 'pending')
            ->orderBy('desired_start_time', 'asc') // Ưu tiên theo thời gian MONG MUỐN bắt đầu đỗ
            ->orderBy('requested_at', 'asc') // Sắp xếp theo thời gian đặt
            ->get();

        $totalRequests = $requests->count();
        $successCount = 0;
        $conflictCount = 0;
        $totalProcessingTime = 0;
        $processingTimes = [];

        if ($algorithm === 'priority_queue') {
            // Xử lý tuần tự từng request
            foreach ($requests as $request) {
                $allocatedSlot = PriorityQueueSlotAllocationService::allocateSlot($request);

                // Lấy processing_time từ DB
                $processingTime = $request->fresh()->processing_time_ms ?? 0;
                $totalProcessingTime += $processingTime;
                $processingTimes[] = $processingTime;

                if ($allocatedSlot) {
                    $successCount++;
                    // Tạo reservation khi allocation thành công
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
                    $processingTimes[] = $request->fresh()->processing_time_ms ?? 0;
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

    /**
     * Tạo reservation từ request và slot đã được cấp
     */
    private function createReservation($request, $slot)
    {
        // Đảm bảo timezone UTC khi lưu vào DB
        $startTime = $request->desired_start_time->copy()->utc();
        $endTime = $startTime->copy()->addMinutes($request->duration_minutes);
        $expiresAt = $startTime->copy()->addMinutes(15);

        Reservation::create([
            'user_id' => null,
            'vehicle_id' => null,
            'slot_id' => $slot->id,
            'reservation_request_id' => $request->id,
            'reservation_code' => 'RES-' . strtoupper(uniqid()) . '-' . now()->format('Ymd'),
            'status' => 'confirmed',
            'start_time' => $startTime, // Thời gian bắt đầu đỗ,
            'end_time' => $endTime, // Thời gian kết thúc đỗ
            'expires_at' => $expiresAt // Thời gian hết hạn giữ chỗ
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

    /**
     * Tính độ sử dụng chỗ đỗ
     *
     * Công thức: (Số slot có ít nhất 1 reservation / Tổng số slot) × 100%
     */
    private function calculateUtilization()
    {
        $totalSlots = ParkingSlot::count();
        $usedSlots = Reservation::whereIn('status', ['confirmed', 'checked_in'])->distinct('slot_id')->count();

        return $totalSlots > 0 ? ($usedSlots / $totalSlots) * 100 : 0;
    }
}
