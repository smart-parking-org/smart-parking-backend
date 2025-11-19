<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Models\Gate;
use App\Services\HungarianSlotAllocationService;
use App\Services\PriorityQueueSlotAllocationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * COMMAND: MÔ PHỎNG BÃI ĐỖ XE THỜI GIAN THỰC
 *
 * Mục đích:
 * - Mô phỏng bãi đỗ xe đang hoạt động với xe vào và xe ra
 * - Khởi tạo 200 slot đã có xe (checked_in)
 * - Tạo request từng đợt (ví dụ 10 người) và xử lý checkin
 * - Trong lúc đó có xe checkout ra một cách ngẫu nhiên
 * - Không tạo trước 300 request, tạo và xử lý theo thời gian thực
 * - Đánh giá theo yêu cầu: xung đột < 2%, thời gian TB < 1.5s
 *
 * Cách sử dụng:
 * php artisan simulate:real-time-parking --total-requests=300 --batch-size=10 --initial-occupied=200 --algorithm=priority_queue
 * php artisan simulate:real-time-parking --total-requests=300 --batch-size=10 --initial-occupied=200 --algorithm=hungarian
 */
class SimulateRealTimeParking extends Command
{
    protected $signature = 'simulate:real-time-parking
                            {--total-requests=300 : Tổng số requests}
                            {--batch-size=10 : Số requests mỗi đợt}
                            {--initial-occupied=200 : Số slot đã có xe ban đầu}
                            {--algorithm=priority_queue : Thuật toán sử dụng}
                            {--checkout-probability=30 : Xác suất checkout mỗi đợt (%)}
                            {--peak-ratio=60 : Tỷ lệ giờ cao điểm (%)}';
    
    protected $description = 'Mô phỏng bãi đỗ xe thời gian thực với xe vào và xe ra';

    private $totalRequests;
    private $batchSize;
    private $initialOccupied;
    private $algorithm;
    private $checkoutProbability;
    private $peakRatio;
    private $parkingLot;
    private $gates;

    public function handle()
    {
        $this->totalRequests = (int) $this->option('total-requests');
        $this->batchSize = (int) $this->option('batch-size');
        $this->initialOccupied = (int) $this->option('initial-occupied');
        $this->algorithm = $this->option('algorithm');
        $this->checkoutProbability = (int) $this->option('checkout-probability');
        $this->peakRatio = (int) $this->option('peak-ratio');

        $algorithmNameMap = [
            'priority_queue' => 'Priority Queue',
            'hungarian' => 'Hungarian',
        ];

        $this->info("🚗 MÔ PHỎNG BÃI ĐỖ XE THỜI GIAN THỰC");
        $this->info("═══════════════════════════════════════");
        $this->info("Tổng requests: {$this->totalRequests}");
        $this->info("Kích thước đợt: {$this->batchSize}");
        $this->info("Slot đã có xe ban đầu: {$this->initialOccupied}");
        $this->info("Thuật toán: " . $algorithmNameMap[$this->algorithm]);
        $this->info("Xác suất checkout mỗi đợt: {$this->checkoutProbability}%");
        $this->info("Tỷ lệ giờ cao điểm: {$this->peakRatio}%");
        $this->info("═══════════════════════════════════════\n");

        // Bước 1: Reset dữ liệu cũ
        $this->resetData();

        // Bước 2: Lấy hoặc tạo parking lot và gates
        $this->setupParkingLotAndGates();

        // Bước 3: Khởi tạo 200 slot đã có xe
        $this->initializeOccupiedSlots();

        // Bước 4: Chạy mô phỏng thời gian thực
        $this->runRealTimeSimulation($algorithmNameMap);

        $this->info("\n✅ Hoàn thành mô phỏng!");
    }

    /**
     * Reset dữ liệu cũ
     */
    private function resetData()
    {
        $this->info("🔄 Đang reset dữ liệu cũ...");
        Schema::disableForeignKeyConstraints();
        Reservation::truncate();
        ReservationRequest::truncate();
        Schema::enableForeignKeyConstraints();
        ParkingSlot::query()->update(['status' => 'available']);
        $this->info("✅ Đã reset dữ liệu\n");
    }

    /**
     * Setup parking lot và gates
     */
    private function setupParkingLotAndGates()
    {
        $this->parkingLot = ParkingLot::first();
        if (!$this->parkingLot) {
            $this->error('Không tìm thấy bãi đỗ xe nào');
            exit(1);
        }

        // Tạo gates nếu chưa có
        $this->gates = Gate::where('parking_lot_id', $this->parkingLot->id)->get();
        if ($this->gates->isEmpty()) {
            $this->info("📝 Đang tạo cổng...");
            // Tạo 3 cổng mặc định
            $gates = [
                ['gate_code' => 'GATE-01', 'gate_type' => 'both', 'position_x' => 0, 'position_y' => 0],
                ['gate_code' => 'GATE-02', 'gate_type' => 'both', 'position_x' => 100, 'position_y' => 0],
                ['gate_code' => 'GATE-03', 'gate_type' => 'both', 'position_x' => 50, 'position_y' => 100],
            ];

            foreach ($gates as $gateData) {
                Gate::create(array_merge($gateData, [
                    'parking_lot_id' => $this->parkingLot->id,
                    'is_active' => true,
                ]));
            }

            $this->gates = Gate::where('parking_lot_id', $this->parkingLot->id)->get();
            $this->info("✅ Đã tạo " . $this->gates->count() . " cổng\n");
        }

        // Tính toán và lưu khoảng cách từ slot đến cổng
        $this->calculateSlotGateDistances();
    }

    /**
     * Tính toán khoảng cách từ slot đến cổng
     */
    private function calculateSlotGateDistances()
    {
        $this->info("📏 Đang tính toán khoảng cách từ slot đến cổng...");
        
        $slots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)->get();
        $gates = $this->gates;

        DB::table('slot_gate_distances')->whereIn('slot_id', $slots->pluck('id'))->delete();

        $distances = [];
        foreach ($slots as $slot) {
            foreach ($gates as $gate) {
                // Tính khoảng cách Euclidean
                $distance = sqrt(
                    pow(($slot->position_x ?? 0) - ($gate->position_x ?? 0), 2) +
                    pow(($slot->position_y ?? 0) - ($gate->position_y ?? 0), 2)
                );

                $distances[] = [
                    'slot_id' => $slot->id,
                    'gate_id' => $gate->id,
                    'distance' => round($distance, 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insert batch
        $chunks = array_chunk($distances, 500);
        foreach ($chunks as $chunk) {
            DB::table('slot_gate_distances')->insert($chunk);
        }

        $this->info("✅ Đã tính toán khoảng cách cho " . count($distances) . " cặp slot-cổng\n");
    }

    /**
     * Khởi tạo 200 slot đã có xe (checked_in)
     */
    private function initializeOccupiedSlots()
    {
        $this->info("🚗 Đang khởi tạo {$this->initialOccupied} slot đã có xe...");

        $slots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
            ->where('status', 'available')
            ->inRandomOrder()
            ->limit($this->initialOccupied)
            ->get();

        if ($slots->count() < $this->initialOccupied) {
            $this->warn("⚠️ Chỉ có {$slots->count()} slot available, không đủ {$this->initialOccupied} slot");
        }

        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $types = [
            'motorbike' => 0.5,
            'car_4_seat' => 0.33,
            'car_7_seat' => 0.12,
            'light_truck' => 0.05,
        ];

        $created = 0;
        foreach ($slots as $slot) {
            // Chỉ tạo reservation cho slot cùng loại xe
            if (!in_array($slot->vehicle_type, $vehicleTypes)) {
                continue;
            }

            // Tạo reservation với thời gian còn active (end_time > now())
            // Một số đã checkin trước đó, một số sắp checkin
            // Giới hạn tất cả trong ngày hôm nay
            $todayEnd = Carbon::today()->endOfDay(); // 23:59:59 hôm nay
            
            $timeOffset = rand(-6, 2); // -6 giờ đến +2 giờ so với now()
            $startTime = now()->addHours($timeOffset);
            $duration = rand(2, 8) * 60; // 2-8 giờ
            $endTime = $startTime->copy()->addMinutes($duration);
            
            // Đảm bảo end_time > now() (còn active)
            // Nếu đã hết thời gian, đáng lẽ đã checkout rồi
            if ($endTime <= now()) {
                $endTime = now()->addHours(rand(1, 4)); // Kéo dài thêm 1-4 giờ
                $startTime = $endTime->copy()->subMinutes($duration);
            }
            
            // ✅ Giới hạn end_time không vượt quá 23:59:59 hôm nay
            if ($endTime > $todayEnd) {
                $endTime = $todayEnd->copy();
                // Điều chỉnh start_time để duration hợp lý
                $startTime = $endTime->copy()->subMinutes($duration);
                // Đảm bảo start_time không quá sớm (ít nhất là đầu ngày)
                if ($startTime < Carbon::today()) {
                    $startTime = Carbon::today()->copy();
                    $endTime = $startTime->copy()->addMinutes($duration);
                    // Nếu vẫn vượt quá, giảm duration
                    if ($endTime > $todayEnd) {
                        $endTime = $todayEnd->copy();
                    }
                }
            }
            
            // Checkin time không thể sau start_time
            // Nếu start_time trong tương lai, checkin = start_time
            // Nếu start_time trong quá khứ, checkin = start_time (đã checkin rồi)
            $checkInTime = min($startTime->copy(), now());

            // Tạo reservation với status checked_in
            Reservation::create([
                'user_id' => null,
                'vehicle_id' => null,
                'slot_id' => $slot->id,
                'reservation_request_id' => null,
                'reservation_code' => 'RES-' . strtoupper(uniqid()) . '-' . now()->format('Ymd'),
                'status' => 'checked_in',
                'start_time' => $startTime->utc(),
                'end_time' => $endTime->utc(),
                'check_in_at' => $checkInTime->utc(),
                'expires_at' => $startTime->copy()->addMinutes(15)->utc(),
            ]);

            // Cập nhật slot status
            $slot->update(['status' => 'occupied']);
            $created++;
        }

        $this->info("✅ Đã khởi tạo {$created} slot đã có xe\n");
    }

    /**
     * Chạy mô phỏng thời gian thực
     */
    private function runRealTimeSimulation($algorithmNameMap)
    {
        $this->info("🔧 Bắt đầu mô phỏng thời gian thực...\n");

        $totalBatches = ceil($this->totalRequests / $this->batchSize);
        $peakRequests = intval($this->totalRequests * $this->peakRatio / 100);
        $normalRequests = $this->totalRequests - $peakRequests;

        $successCount = 0;
        $conflictCount = 0;
        $totalProcessingTime = 0;
        $processingTimes = [];
        $peakRequestCount = 0;
        $normalRequestCount = 0;

        $this->info("📊 Sẽ tạo {$totalBatches} đợt, mỗi đợt {$this->batchSize} requests\n");

        for ($batch = 1; $batch <= $totalBatches; $batch++) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📦 Đợt {$batch}/{$totalBatches}");

            // Bước 1: Xử lý checkout ngẫu nhiên (trước khi tạo request mới)
            $this->processRandomCheckouts();

            // Bước 2: Tạo batch requests mới
            $requestsInBatch = min($this->batchSize, $this->totalRequests - (($batch - 1) * $this->batchSize));
            $requests = $this->createBatchRequests($requestsInBatch, $peakRequestCount, $normalRequestCount, $peakRequests, $normalRequests);

            // Bước 3: Xử lý allocation
            $batchResults = $this->processBatchAllocation($requests, $algorithmNameMap);

            // Bước 4: Xử lý checkin cho các reservation đã được tạo
            $this->processBatchCheckin($batchResults['reservations']);

            // Cập nhật metrics
            $successCount += $batchResults['success'];
            $conflictCount += $batchResults['failed'];
            $totalProcessingTime += $batchResults['total_processing_time'];
            $processingTimes = array_merge($processingTimes, $batchResults['processing_times']);

            $this->info("   ✅ Thành công: {$batchResults['success']}, ❌ Thất bại: {$batchResults['failed']}");
            $this->info("   ⏱️  Thời gian xử lý TB: " . round($batchResults['avg_processing_time'], 2) . "ms");
            $this->info("");

            // Delay nhỏ giữa các đợt để mô phỏng thời gian thực
            usleep(100000); // 0.1 giây
        }

        // Tính kết quả cuối cùng
        $avgProcessingTime = count($processingTimes) > 0 ? array_sum($processingTimes) / count($processingTimes) : 0;
        $maxProcessingTime = !empty($processingTimes) ? max($processingTimes) : 0;
        $successRate = $this->totalRequests > 0 ? ($successCount / $this->totalRequests) * 100 : 0;
        $conflictRate = $this->totalRequests > 0 ? ($conflictCount / $this->totalRequests) * 100 : 0;
        $utilizationRate = $this->calculateUtilization();

        // Hiển thị kết quả
        $this->displayResults(
            $this->totalRequests,
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
     * Xử lý checkout ngẫu nhiên
     * Tính số lượng checkout dựa trên % của tổng số reservation checked_in hiện tại
     */
    private function processRandomCheckouts()
    {
        // Lấy tổng số reservation đang checked_in
        $totalCheckedIn = Reservation::where('status', 'checked_in')
            ->where('end_time', '>', now())
            ->count();

        if ($totalCheckedIn == 0) {
            return;
        }

        // Tính số lượng checkout = % của tổng số checked_in
        $checkoutCount = (int) round($totalCheckedIn * $this->checkoutProbability / 100);
        
        // Giới hạn tối đa để không checkout quá nhiều một lúc
        // Ví dụ: tối đa 20% số checked_in mỗi đợt
        $maxCheckout = max(1, (int) round($totalCheckedIn * 0.2));
        $checkoutCount = min($checkoutCount, $maxCheckout);

        if ($checkoutCount > 0) {
            // Lấy ngẫu nhiên $checkoutCount reservation để checkout
            $reservationsToCheckout = Reservation::where('status', 'checked_in')
                ->where('end_time', '>', now())
                ->inRandomOrder()
                ->limit($checkoutCount)
                ->get();

            foreach ($reservationsToCheckout as $reservation) {
                // Checkout: chuyển sang checked_out
                $reservation->update([
                    'status' => 'checked_out',
                    'check_out_at' => now(),
                ]);

                // Slot trở lại available
                $reservation->slot->update(['status' => 'available']);
            }

            $this->info("   🚪 Có {$checkoutCount} xe checkout (từ {$totalCheckedIn} xe đang đỗ)");
        }
    }

    /**
     * Tạo batch requests mới
     */
    private function createBatchRequests($count, &$peakCount, &$normalCount, $peakTotal, $normalTotal)
    {
        $requests = [];
        $peakHours = [
            ['start' => '07:00', 'end' => '09:00'],
            ['start' => '17:30', 'end' => '19:30']
        ];

        $types = [
            'motorbike' => 0.5,
            'car_4_seat' => 0.33,
            'car_7_seat' => 0.12,
            'light_truck' => 0.05,
        ];

        for ($i = 0; $i < $count; $i++) {
            // Quyết định là peak hay normal
            $isPeak = ($peakCount < $peakTotal) && ($normalCount >= $normalTotal || rand(1, 100) <= $this->peakRatio);

            $startTime = null;
            $duration = 0;

            if ($isPeak && $peakCount < $peakTotal) {
                // Peak hour request
                $peakHour = $peakHours[array_rand($peakHours)];
                $startTime = $this->randomTimeInRange($peakHour['start'], $peakHour['end']);
                
                // Nếu không tạo được (khung giờ đã qua), chuyển sang normal hour
                if (!$startTime) {
                    $startTime = $this->randomNormalHour();
                    $duration = rand(4, 12) * 30; // 2-6 giờ
                    $normalCount++;
                    // Không tăng peakCount vì không tạo được peak hour request
                } else {
                    $duration = rand(8, 16) * 30; // 4-8 giờ
                    $peakCount++;
                }
            } else {
                // Normal hour request
                $startTime = $this->randomNormalHour();
                $duration = rand(4, 12) * 30; // 2-6 giờ
                $normalCount++;
            }

            // Nếu vẫn không tạo được startTime (tất cả giờ đã qua), bỏ qua request này
            if (!$startTime) {
                continue;
            }

            // ✅ Giới hạn end_time không vượt quá 23:59:59 hôm nay
            $todayEnd = Carbon::today()->endOfDay(); // 23:59:59 hôm nay
            $endTime = $startTime->copy()->addMinutes($duration);
            
            if ($endTime > $todayEnd) {
                // Điều chỉnh duration để không vượt quá 23:59:59
                $maxDuration = $startTime->diffInMinutes($todayEnd);
                if ($maxDuration > 0) {
                    $duration = min($duration, $maxDuration);
                } else {
                    // Nếu startTime đã quá gần cuối ngày, bỏ qua request này
                    continue;
                }
            }

            $vehicleType = $this->selectVehicleType($types);

            $request = ReservationRequest::create([
                'parking_lot_id' => $this->parkingLot->id,
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime,
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => now()
            ]);

            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Xử lý allocation cho batch requests
     */
    private function processBatchAllocation($requests, $algorithmNameMap)
    {
        $success = 0;
        $failed = 0;
        $totalProcessingTime = 0;
        $processingTimes = [];
        $reservations = [];

        // Sắp xếp requests theo thời gian
        $sortedRequests = collect($requests)->sortBy([
            ['desired_start_time', 'asc'],
            ['requested_at', 'asc']
        ]);

        if ($this->algorithm === 'priority_queue') {
            foreach ($sortedRequests as $request) {
                $startTime = microtime(true);
                $allocatedSlot = PriorityQueueSlotAllocationService::allocateSlot($request);
                $processingTime = (microtime(true) - $startTime) * 1000;

                $totalProcessingTime += $processingTime;
                $processingTimes[] = $processingTime;

                if ($allocatedSlot) {
                    $success++;
                    $reservation = $this->createReservation($request, $allocatedSlot);
                    $reservations[] = $reservation;
                } else {
                    $failed++;
                }
            }
        } else {
            // Hungarian - xử lý batch
            $batchStartTime = microtime(true);
            $result = HungarianSlotAllocationService::allocateBatch($sortedRequests);
            $batchTotalTime = (microtime(true) - $batchStartTime) * 1000;

            $success = $result['stats']['success'];
            $failed = $result['stats']['failed'];
            $totalProcessingTime = $batchTotalTime; // Sử dụng thời gian thực tế đo được

            // Tính thời gian trung bình cho mỗi request
            $avgTimePerRequest = $sortedRequests->count() > 0 ? $batchTotalTime / $sortedRequests->count() : 0;

            foreach ($result['allocations'] as $allocation) {
                $request = ReservationRequest::find($allocation['request_id']);
                $slot = ParkingSlot::find($allocation['slot_id']);

                if ($request && $slot) {
                    $reservation = $this->createReservation($request, $slot);
                    $reservations[] = $reservation;
                    // Sử dụng thời gian trung bình cho mỗi request
                    $processingTimes[] = $avgTimePerRequest;
                }
            }

            // Thêm thời gian cho các request failed (nếu có)
            for ($i = 0; $i < $failed; $i++) {
                $processingTimes[] = $avgTimePerRequest;
            }
        }

        // Tính avg_processing_time từ mảng processing_times
        $avgProcessingTime = count($processingTimes) > 0 
            ? array_sum($processingTimes) / count($processingTimes) 
            : 0;

        return [
            'success' => $success,
            'failed' => $failed,
            'total_processing_time' => $totalProcessingTime,
            'processing_times' => $processingTimes,
            'avg_processing_time' => $avgProcessingTime,
            'reservations' => $reservations,
        ];
    }

    /**
     * Xử lý checkin cho batch reservations
     */
    private function processBatchCheckin($reservations)
    {
        foreach ($reservations as $reservation) {
            // Chỉ checkin nếu reservation đã đến thời gian start_time
            if ($reservation->start_time <= now()) {
                $reservation->update([
                    'status' => 'checked_in',
                    'check_in_at' => now(),
                ]);

                $reservation->slot->update(['status' => 'occupied']);
            }
        }
    }

    /**
     * Tạo reservation từ request và slot
     */
    private function createReservation($request, $slot)
    {
        $startTime = $request->desired_start_time->copy()->utc();
        $endTime = $startTime->copy()->addMinutes($request->duration_minutes);
        
        // ✅ Giới hạn end_time không vượt quá 23:59:59 hôm nay
        $todayEnd = Carbon::today()->endOfDay()->utc();
        if ($endTime > $todayEnd) {
            $endTime = $todayEnd->copy();
        }
        
        $expiresAt = $startTime->copy()->addMinutes(15);
        // Đảm bảo expires_at cũng không vượt quá cuối ngày
        if ($expiresAt > $todayEnd) {
            $expiresAt = $todayEnd->copy();
        }

        return Reservation::create([
            'user_id' => null,
            'vehicle_id' => null,
            'slot_id' => $slot->id,
            'reservation_request_id' => $request->id,
            'reservation_code' => 'RES-' . strtoupper(uniqid()) . '-' . now()->format('Ymd'),
            'status' => 'confirmed',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Chọn loại xe theo phân bố xác suất
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
     * Random thời gian trong khung giờ (chỉ trong ngày hôm nay, chưa qua)
     */
    private function randomTimeInRange($startTime, $endTime)
    {
        $today = Carbon::today();
        $start = $today->copy()->setTimeFromTimeString($startTime);
        $end = $today->copy()->setTimeFromTimeString($endTime);
        
        // Nếu khung giờ đã qua, không tạo request
        if ($end < now()) {
            return null;
        }
        
        // Đảm bảo start không quá sớm (ít nhất là now() nếu đã qua)
        if ($start < now()) {
            $start = now()->copy();
        }
        
        // Nếu start >= end, không có khoảng thời gian hợp lệ
        if ($start >= $end) {
            return null;
        }

        $randomMinutes = rand(0, max(0, $start->diffInMinutes($end)));
        return $start->copy()->addMinutes($randomMinutes);
    }

    /**
     * Random giờ bình thường (chỉ trong ngày hôm nay, chưa qua)
     */
    private function randomNormalHour()
    {
        $today = Carbon::today();
        $currentHour = now()->hour;
        $currentMinute = now()->minute;
        
        // Random giờ từ hiện tại đến 23:59
        $hour = rand($currentHour, 23);
        
        // Tránh giờ cao điểm: 7-9h sáng và 17-19h chiều
        while (($hour >= 7 && $hour < 9) || ($hour >= 17 && $hour < 19)) {
            $hour = rand($currentHour, 23);
            // Nếu không tìm được giờ hợp lệ, chọn giờ ngẫu nhiên khác
            if ($hour >= 23) break;
        }
        
        // Nếu là giờ hiện tại, minute phải >= minute hiện tại
        $minute = ($hour == $currentHour) ? rand($currentMinute, 59) : rand(0, 59);
        
        return $today->copy()->setHour($hour)->setMinute($minute)->setSecond(0);
    }

    /**
     * Hiển thị kết quả
     */
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
        $this->info("\n📊 KẾT QUẢ MÔ PHỎNG:");
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

    /**
     * Đánh giá kết quả
     */
    private function evaluateResults($conflictRate, $avgProcessingTime)
    {
        $this->info("\n🎯 ĐÁNH GIÁ THEO YÊU CẦU ĐỀ TÀI:");

        $conflictPass = $conflictRate < 2;
        $timePass = $avgProcessingTime < 1500;

        if ($conflictPass && $timePass) {
            $this->info("🎉 THUẬT TOÁN ĐẠT YÊU CẦU:");
            $this->info("   ✅ Xung đột: {$conflictRate}% < 2%");
            $this->info("   ✅ Thời gian: {$avgProcessingTime}ms < 1500ms");
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
     */
    private function calculateUtilization()
    {
        $totalSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)->count();
        $usedSlots = Reservation::whereIn('status', ['confirmed', 'checked_in'])
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->distinct('slot_id')
            ->count();

        return $totalSlots > 0 ? ($usedSlots / $totalSlots) * 100 : 0;
    }
}

