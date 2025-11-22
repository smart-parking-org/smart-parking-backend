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
 * ============================================================================
 * MỤC ĐÍCH VÀ PHẠM VI MÔ PHỎNG
 * ============================================================================
 *
 * Mô phỏng này được thiết kế để đánh giá hiệu quả của 2 thuật toán cấp chỗ:
 * - Priority Queue: Thuật toán tham lam, cấp chỗ từng request một
 * - Hungarian (Kuhn-Munkres): Thuật toán tối ưu, cấp chỗ theo batch
 *
 * Mục tiêu đánh giá:
 * - Tỷ lệ xung đột < 2%
 * - Thời gian xử lý trung bình < 1.5 giây
 *
 * ============================================================================
 * CÁCH TẠO DỮ LIỆU MÔ PHỎNG
 * ============================================================================
 *
 * 1. KHỞI TẠO TRẠNG THÁI BAN ĐẦU (initializeOccupiedSlots):
 *    - Tạo N slot đã có xe đỗ sẵn (status = 'checked_in')
 *    - Phân bổ theo loại xe: 50% xe máy, 35% ô tô 4 chỗ, 12% ô tô 7 chỗ, 3% xe tải
 *    - Thời gian đỗ đa dạng để mô phỏng thực tế:
 *      * 20% đỗ ngắn hạn (30 phút - 1 giờ): checkout 6:30-7:00
 *      * 30% đỗ trung hạn (1-2 giờ): checkout 7:00-8:00
 *      * 30% đỗ dài hạn (2-3 giờ): checkout 8:00-9:00
 *      * 20% đỗ rất dài (3-4 giờ): checkout 9:00-10:00
 *    - Tất cả xe checkout trong khoảng 6:00-10:00 để có xe ra vào liên tục
 *
 * 2. TẠO REQUESTS THEO THỜI GIAN THỰC (createRealTimeRequests):
 *    - Không tạo trước tất cả requests, mà tạo dần theo thời gian mô phỏng
 *    - Phân bổ 60% requests vào giờ cao điểm (7:00-9:00), 40% vào giờ bình thường
 *    - Mỗi request có:
 *      * desired_start_time = thời gian hiện tại (có thể checkin ngay)
 *      * duration_minutes: Peak = 4-10 giờ, Normal = 1-6 giờ (bước nhảy 30 phút)
 *      * vehicle_type: Phân bổ theo tỷ lệ slot thực tế trong bãi
 *      * gate_id: Chọn cổng ngẫu nhiên
 *
 * 3. XỬ LÝ ALLOCATION:
 *    - Priority Queue: Xử lý từng request một, chọn slot gần cổng nhất
 *    - Hungarian: Xử lý batch, tối ưu tổng chi phí (khoảng cách + xung đột)
 *
 * ============================================================================
 * LOGIC CHECKIN VÀ CHECKOUT
 * ============================================================================
 *
 * 1. CHECKIN (processPendingCheckins):
 *    - Điều kiện checkin:
 *      * Reservation status = 'confirmed'
 *      * start_time <= thời gian mô phỏng hiện tại
 *      * expires_at >= thời gian mô phỏng hiện tại (chưa hết hạn giữ chỗ 15 phút)
 *      * check_in_at = null (chưa checkin)
 *    - Khi checkin:
 *      * Reservation status → 'checked_in'
 *      * Slot status → 'occupied'
 *      * check_in_at = max(start_time, current_time)
 *
 * 2. CHECKOUT (processRandomCheckouts):
 *    - Checkout đúng giờ:
 *      * Reservation status = 'checked_in'
 *      * end_time <= thời gian mô phỏng hiện tại
 *      * Tự động checkout khi đến đúng end_time
 *    - Checkout sớm (mô phỏng thực tế):
 *      * 15% xe checkout sớm 15-30 phút trước end_time
 *      * Chỉ áp dụng nếu đã checkin ít nhất 30 phút
 *      * Xe trong vòng 30 phút tới sẽ checkout có 15% xác suất checkout sớm
 *    - Khi checkout:
 *      * Reservation status → 'checked_out'
 *      * Slot status → 'available' (nếu không còn reservation active khác)
 *      * check_out_at = end_time (đúng giờ) hoặc early_checkout_time (sớm)
 *
 * 3. EXPIRED RESERVATIONS (processExpiredReservations):
 *    - Reservation status = 'confirmed' nhưng quá hạn giữ chỗ (expires_at < current_time)
 *    - Tự động hủy và giải phóng slot
 *    - Cập nhật reservation_request status tương ứng
 *
 * ============================================================================
 * QUY TRÌNH MÔ PHỎNG
 * ============================================================================
 *
 * Mỗi bước mô phỏng (1 phút):
 * 1. Xử lý checkin các reservation đã đến giờ
 * 2. Hủy các reservation quá hạn giữ chỗ
 * 3. Xử lý checkout các reservation đã đến end_time
 * 4. Tạo requests mới (nếu còn thiếu và đúng thời điểm)
 * 5. Xử lý allocation cho requests vừa tạo
 * 6. Tăng thời gian mô phỏng lên 1 phút
 *
 * Vòng lặp tiếp tục cho đến khi:
 * - Đã tạo đủ số requests yêu cầu
 * - Đã phân bổ đúng 60% peak, 40% normal
 * - Đã đến thời gian kết thúc mô phỏng (10:00)
 *
 * ============================================================================
 * CÁCH SỬ DỤNG - COPY CÁC LỆNH SAU:
 * ============================================================================
 *
 * 1. CHẠY RIÊNG THUẬT TOÁN PRIORITY QUEUE:
 * php artisan simulate:real-time-parking --total-requests=300 --peak-ratio=60 --algorithm=priority_queue --initial-occupied=200
 *
 * 2. CHẠY RIÊNG THUẬT TOÁN HUNGARIAN:
 * php artisan simulate:real-time-parking --total-requests=300 --peak-ratio=60 --algorithm=hungarian --initial-occupied=200
 *
 * 3. SO SÁNH TỰ ĐỘNG 2 THUẬT TOÁN (CHẠY CẢ 2 VÀ SO SÁNH):
 * php artisan test:compare-algorithms --requests=300 --peak-ratio=60
 *
 * ============================================================================
 * LỆNH NGẮN GỌN (Windows PowerShell):
 * ============================================================================
 *
 * Priority Queue:
 * php artisan simulate:real-time-parking --total-requests=300 --peak-ratio=60 --initial-occupied=150 --algorithm=priority_queue
 *
 * Hungarian:
 * php artisan simulate:real-time-parking --total-requests=300 --peak-ratio=60 --algorithm=hungarian
 *
 * So sánh:
 * php artisan test:compare-algorithms --requests=300 --peak-ratio=60
 *
 * ============================================================================
 * GIẢI THÍCH THAM SỐ:
 * ============================================================================
 * --total-requests=300    : Tổng số requests (yêu cầu: 300)
 * --peak-ratio=60        : Tỷ lệ giờ cao điểm 60% (yêu cầu: 60%)
 * --algorithm=...        : priority_queue hoặc hungarian
 * --initial-occupied=200 : Số slot đã có xe ban đầu (tùy chọn)
 *
 * ============================================================================
 * ĐẶC ĐIỂM MÔ PHỎNG
 * ============================================================================
 *
 * - Thời gian mô phỏng: 6:00 - 10:00 (4 giờ)
 * - Bước nhảy thời gian: 1 phút (cố định để đảm bảo độ chính xác)
 * - Thời lượng đỗ: Bước nhảy 30 phút (30, 60, 90, 120, ... phút)
 * - Phân bổ requests: Ngẫu nhiên nhưng đảm bảo đúng 60% peak, 40% normal
 * - Checkout: Tự động đúng end_time + 15% checkout sớm (mô phỏng thực tế)
 * - Giữ chỗ: 15 phút (expires_at = start_time + 15 phút)
 */
class SimulateRealTimeParking extends Command
{
    protected $signature = 'simulate:real-time-parking
                            {--total-requests=300 : Tổng số requests}
                            {--initial-occupied=150 : Số slot đã có xe ban đầu}
                            {--algorithm=priority_queue : Thuật toán sử dụng (priority_queue hoặc hungarian)}
                            {--peak-ratio=60 : Tỷ lệ giờ cao điểm (%)}';

    protected $description = 'Mô phỏng bãi đỗ xe thời gian thực với xe vào và xe ra';

    private $totalRequests;
    private $initialOccupied;
    private $algorithm;
    private $peakRatio;
    private $parkingLot;
    private $gates;
    private $simulationTime;
    private $minParkingDurationMinutes;
    private $lastCheckinCount = 0;
    private $timezone;
    private $lastCheckoutCount = 0;

    public function handle()
    {
        $this->totalRequests = (int) $this->option('total-requests');
        $this->initialOccupied = (int) $this->option('initial-occupied');
        $this->algorithm = $this->option('algorithm');
        $this->peakRatio = (int) $this->option('peak-ratio');
        $this->minParkingDurationMinutes = 15; // Tối thiểu 15 phút
        $this->timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $this->simulationTime = Carbon::today($this->timezone)
            ->setHour(6)
            ->setMinute(0)
            ->setSecond(0);

        $algorithmNameMap = [
            'priority_queue' => 'Priority Queue',
            'hungarian' => 'Hungarian',
        ];

        $this->info("🚗 MÔ PHỎNG BÃI ĐỖ XE THỜI GIAN THỰC");
        $this->info("═══════════════════════════════════════");
        $this->info("Tổng requests: {$this->totalRequests}");
        $this->info("Slot đã có xe ban đầu: {$this->initialOccupied}");
        $this->info("Thuật toán: " . $algorithmNameMap[$this->algorithm]);
        $this->info("Tỷ lệ giờ cao điểm: {$this->peakRatio}%");
        $this->info("═══════════════════════════════════════\n");

        // Bước 1: Reset dữ liệu cũ
        $this->resetData();

        // Bước 2: Lấy hoặc tạo parking lot và gates
        $this->setupParkingLotAndGates();

        // Hiển thị tỷ lệ slot thực tế trong bãi (sau khi đã setup)
        $vehicleTypeDistribution = $this->getVehicleTypeDistributionFromSlots();
        $this->info("📊 Tỷ lệ slot trong bãi (dùng để phân bổ requests):");
        $typeNames = [
            'motorbike' => 'Xe máy',
            'car_4_seat' => 'Ô tô 4 chỗ',
            'car_7_seat' => 'Ô tô 7 chỗ',
            'light_truck' => 'Xe tải nhẹ',
        ];
        foreach ($vehicleTypeDistribution as $type => $ratio) {
            $totalSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $type)
                ->count();
            $percentage = round($ratio * 100, 1);
            $this->info("   - {$typeNames[$type]}: {$totalSlots} slots ({$percentage}%)");
        }
        $this->info("");

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

        // Lấy gates đã được seed (không tạo mới)
        $this->gates = Gate::where('parking_lot_id', $this->parkingLot->id)->get();
        if ($this->gates->isEmpty()) {
            $this->error('Không tìm thấy cổng nào. Vui lòng chạy GateSeeder trước.');
            exit(1);
        }

        $this->info("✅ Sử dụng " . $this->gates->count() . " cổng đã có sẵn");

        // Kiểm tra dữ liệu khoảng cách đã có từ SlotGateDistanceSeeder
        $slots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)->get();
        $expectedCount = $slots->count() * $this->gates->count();
        $existingCount = DB::table('slot_gate_distances')
            ->whereIn('slot_id', $slots->pluck('id'))
            ->count();

        if ($existingCount < $expectedCount) {
            $this->warn("⚠️ Thiếu dữ liệu khoảng cách slot-cổng. Vui lòng chạy SlotGateDistanceSeeder trước.");
        } else {
            $this->info("✅ Đã có dữ liệu khoảng cách slot-cổng ({$existingCount} bản ghi)\n");
        }
    }

    /**
     * KHỞI TẠO DỮ LIỆU MÔ PHỎNG BAN ĐẦU
     *
     * Mục đích: Tạo N slot đã có xe đỗ sẵn để mô phỏng trạng thái bãi đỗ xe thực tế
     *
     * Quy trình:
     * 1. Phân bổ số lượng slot theo loại xe (50% xe máy, 35% ô tô 4 chỗ, 12% ô tô 7 chỗ, 3% xe tải)
     * 2. Đảm bảo tạo đúng số lượng yêu cầu (--initial-occupied)
     * 3. Tạo reservation với thời gian đỗ đa dạng:
     *    - 20% đỗ ngắn hạn (30 phút - 1 giờ): checkout 6:30-7:00
     *    - 30% đỗ trung hạn (1-2 giờ): checkout 7:00-8:00
     *    - 30% đỗ dài hạn (2-3 giờ): checkout 8:00-9:00
     *    - 20% đỗ rất dài (3-4 giờ): checkout 9:00-10:00
     * 4. Tất cả xe checkout trong khoảng simulation (6:00-10:00) để có xe ra vào liên tục
     *
     * Kết quả: Tạo reservation với status = 'checked_in', slot status = 'occupied'
     *
     * @return void
     */
    private function initializeOccupiedSlots()
    {
        $this->info("🚗 Đang khởi tạo {$this->initialOccupied} slot đã có xe...");

        // Phân bổ slot đã có xe theo loại xe (tỷ lệ phù hợp thực tế)
        $vehicleTypeDistribution = [
            'motorbike' => 0.50,    // 50% xe máy
            'car_4_seat' => 0.35,   // 35% ô tô 4 chỗ
            'car_7_seat' => 0.12,   // 12% ô tô 7 chỗ
            'light_truck' => 0.03,  // 3% xe tải
        ];

        $created = 0;
        $todayStart = $this->simulationTime->copy()->startOfDay();
        $todayEnd = $this->simulationTime->copy()->endOfDay();

        // Tính tổng số slot available để đảm bảo tạo đủ số lượng
        $totalAvailableSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
            ->where('status', 'available')
            ->count();

        // Đảm bảo không vượt quá số slot available
        $targetInitialOccupied = min($this->initialOccupied, $totalAvailableSlots);

        // Tính số slot available cho mỗi loại xe
        $totalSlotsByType = [];
        $availableSlotsByType = [];
        foreach ($vehicleTypeDistribution as $vehicleType => $ratio) {
            $totalSlotsByType[$vehicleType] = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $vehicleType)
                ->count();
            $availableSlotsByType[$vehicleType] = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $vehicleType)
                ->where('status', 'available')
                ->count();
        }

        // Phân bổ hợp lý: Ưu tiên tạo đủ số lượng yêu cầu
        // Bắt đầu với giới hạn 50-60%, nếu không đủ thì tăng dần
        $allocatedByType = [];
        $remainingToAllocate = $targetInitialOccupied;

        // Bước 1: Phân bổ theo tỷ lệ với giới hạn ban đầu (50-60%)
        foreach ($vehicleTypeDistribution as $vehicleType => $ratio) {
            $targetForType = (int) round($targetInitialOccupied * $ratio);
            $maxLimit = (int) round($totalSlotsByType[$vehicleType] * ($vehicleType === 'motorbike' ? 0.6 : 0.5));
            $allocatedByType[$vehicleType] = min($targetForType, $maxLimit, $availableSlotsByType[$vehicleType]);
            $remainingToAllocate -= $allocatedByType[$vehicleType];
        }

        // Bước 2: Nếu còn thiếu, tăng giới hạn để đạt đủ số lượng
        if ($remainingToAllocate > 0) {
            // Tính tỷ lệ cần tăng để đạt đủ
            $currentTotal = array_sum($allocatedByType);
            if ($currentTotal > 0) {
                $scaleFactor = $targetInitialOccupied / $currentTotal;

                // Tăng phân bổ cho từng loại theo tỷ lệ, nhưng không vượt quá slot available
                foreach ($vehicleTypeDistribution as $vehicleType => $ratio) {
                    if ($remainingToAllocate <= 0)
                        break;

                    $newTarget = (int) round($allocatedByType[$vehicleType] * $scaleFactor);
                    $maxCanAllocate = $availableSlotsByType[$vehicleType];

                    if ($newTarget > $allocatedByType[$vehicleType]) {
                        $canAdd = min($newTarget - $allocatedByType[$vehicleType], $maxCanAllocate - $allocatedByType[$vehicleType], $remainingToAllocate);
                        $allocatedByType[$vehicleType] += $canAdd;
                        $remainingToAllocate -= $canAdd;
                    }
                }
            }
        }

        // Bước 3: Nếu vẫn còn thiếu, phân bổ phần còn lại cho các loại còn chỗ (ưu tiên loại có nhiều slot)
        if ($remainingToAllocate > 0) {
            // Sắp xếp theo số slot available còn lại (giảm dần)
            $sortedTypes = [];
            foreach ($vehicleTypeDistribution as $vehicleType => $ratio) {
                $remainingAvailable = $availableSlotsByType[$vehicleType] - $allocatedByType[$vehicleType];
                if ($remainingAvailable > 0) {
                    $sortedTypes[] = [
                        'type' => $vehicleType,
                        'available' => $remainingAvailable,
                        'ratio' => $ratio
                    ];
                }
            }
            usort($sortedTypes, function ($a, $b) {
                return $b['available'] - $a['available'];
            });

            // Phân bổ phần còn lại
            foreach ($sortedTypes as $item) {
                if ($remainingToAllocate <= 0)
                    break;
                $vehicleType = $item['type'];
                $canAdd = min($availableSlotsByType[$vehicleType] - $allocatedByType[$vehicleType], $remainingToAllocate);
                $allocatedByType[$vehicleType] += $canAdd;
                $remainingToAllocate -= $canAdd;
            }
        }

        // Tính số lượng thực tế sẽ tạo
        $actualInitialOccupied = array_sum($allocatedByType);

        foreach ($vehicleTypeDistribution as $vehicleType => $ratio) {
            $countForType = $allocatedByType[$vehicleType] ?? 0;

            $slots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('status', 'available')
                ->where('vehicle_type', $vehicleType)
                ->inRandomOrder()
                ->limit($countForType)
                ->get();

            // Phân bổ reservation đa dạng để mô phỏng bãi đỗ xe chung cư TPHCM:
            // Điều chỉnh để tất cả xe checkout trong khoảng thời gian simulation (6:00-10:00 = 4 giờ)
            // - 20% đỗ ngắn hạn (30 phút - 1 giờ): đi mua đồ, giao hàng → checkout 6:30-7:00
            // - 30% đỗ trung hạn (1-2 giờ): đi làm, đi chơi → checkout 7:00-8:00
            // - 30% đỗ dài hạn (2-3 giờ): đi làm cả ngày → checkout 8:00-9:00
            // - 20% đỗ rất dài (3-4 giờ): đỗ cả ngày → checkout 9:00-10:00
            // Thời gian checkout rải đều trong khoảng simulation để có xe ra vào liên tục
            $slotsArray = $slots->toArray();
            shuffle($slotsArray);
            $totalSlots = count($slotsArray);

            // Tính số lượng mỗi loại
            $veryShortTermCount = (int) round($totalSlots * 0.20); // 20%: 30 phút - 1 giờ
            $shortTermCount = (int) round($totalSlots * 0.30);     // 30%: 1-2 giờ
            $mediumTermCount = (int) round($totalSlots * 0.30);     // 30%: 2-3 giờ
            // Còn lại là long-term: 3-4 giờ

            // Tính thời gian kết thúc simulation để giới hạn checkout
            $simulationEndTime = $this->simulationTime->copy()->addHours(4); // 6:00 -> 10:00
            $maxCheckoutOffset = $this->simulationTime->diffInMinutes($simulationEndTime); // 240 phút

            foreach ($slotsArray as $index => $slotData) {
                $slot = ParkingSlot::find($slotData['id']);
                if (!$slot)
                    continue;

                // Xác định loại thời gian đỗ (giới hạn trong 4 giờ simulation)
                if ($index < $veryShortTermCount) {
                    // Very short-term: 30 phút - 1 giờ (đi mua đồ, giao hàng)
                    $duration = $this->roundTo30Minutes(rand(30, 60)); // 30, 60 phút
                    // Checkout trong 30 phút - 1 giờ tới (6:30-7:00)
                    $checkoutOffset = rand(30, 60); // phút
                } elseif ($index < $veryShortTermCount + $shortTermCount) {
                    // Short-term: 1-2 giờ (đi làm, đi chơi)
                    $duration = $this->roundTo30Minutes(rand(60, 120)); // 60, 90, 120 phút
                    // Checkout trong 1-2 giờ tới (7:00-8:00)
                    $checkoutOffset = rand(60, 120); // phút
                } elseif ($index < $veryShortTermCount + $shortTermCount + $mediumTermCount) {
                    // Medium-term: 2-3 giờ (đi làm cả ngày)
                    $duration = $this->roundTo30Minutes(rand(120, 180)); // 120, 150, 180 phút
                    // Checkout trong 2-3 giờ tới (8:00-9:00)
                    $checkoutOffset = rand(120, 180); // phút
                } else {
                    // Long-term: 3-4 giờ (đỗ cả ngày)
                    $duration = $this->roundTo30Minutes(rand(180, 240)); // 180, 210, 240 phút
                    // Checkout trong 3-4 giờ tới (9:00-10:00)
                    $checkoutOffset = rand(180, 240); // phút
                }

                // Đảm bảo checkout không vượt quá thời gian simulation
                $checkoutOffset = min($checkoutOffset, $maxCheckoutOffset);

                // Tính thời gian checkout: rải đều trong ngày
                // Đảm bảo checkout xảy ra trong tương lai (từ 30 phút đến 10 giờ)
                $endTime = $this->simulationTime->copy()->addMinutes($checkoutOffset);

                // Tính start_time: đảm bảo duration đúng
                $startTime = $endTime->copy()->subMinutes($duration);

                // Nếu start_time trong quá khứ quá xa (> 2 giờ), điều chỉnh lại
                // Điều này mô phỏng xe đã đỗ từ trước
                $maxPastOffset = 120; // Tối đa 2 giờ trong quá khứ
                if ($startTime < $this->simulationTime->copy()->subMinutes($maxPastOffset)) {
                    // Xe đã đỗ từ trước, nhưng không quá 2 giờ
                    $pastOffset = rand(0, $maxPastOffset);
                    $startTime = $this->simulationTime->copy()->subMinutes($pastOffset);
                    $endTime = $startTime->copy()->addMinutes($duration);
                }

                // Đảm bảo end_time > simulationTime
                if ($endTime <= $this->simulationTime) {
                    $endTime = $this->simulationTime->copy()->addMinutes($this->roundTo30Minutes(rand(30, 60)));
                    $startTime = $endTime->copy()->subMinutes($duration);
                }

                // Giới hạn trong ngày
                if ($endTime > $todayEnd) {
                    $endTime = $todayEnd->copy();
                    $startTime = $endTime->copy()->subMinutes($duration);
                    if ($startTime < $todayStart) {
                        $startTime = $todayStart->copy();
                        $endTime = $startTime->copy()->addMinutes($duration);
                        if ($endTime > $todayEnd) {
                            $endTime = $todayEnd->copy();
                        }
                    }
                }

                // Checkin time: nếu start_time trong quá khứ thì đã checkin rồi
                $checkInTime = min($startTime->copy(), $this->simulationTime->copy());

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

                $slot->update(['status' => 'occupied']);
                $created++;
            }
        }

        $this->info("✅ Đã khởi tạo {$created} slot đã có xe (phân bổ theo loại xe)");

        // Debug: Hiển thị thống kê reservation ban đầu
        $reservationStats = \App\Models\Reservation::whereHas('slot', function ($q) {
            $q->where('parking_lot_id', $this->parkingLot->id);
        })
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->get()
            ->groupBy(function ($r) {
                return $r->slot->vehicle_type;
            })
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'avg_duration_hours' => round($group->avg(function ($r) {
                        return $r->start_time->diffInHours($r->end_time);
                    }), 1),
                    'min_end_time' => $group->min('end_time')->format('H:i'),
                    'max_end_time' => $group->max('end_time')->format('H:i'),
                ];
            });

        $this->info("📊 Thống kê reservation ban đầu:");
        foreach ($reservationStats as $type => $stats) {
            $this->info("   - {$type}: {$stats['count']} reservations, avg duration: {$stats['avg_duration_hours']}h, end_time range: {$stats['min_end_time']} - {$stats['max_end_time']}");
        }
    }

    /**
     * CHẠY MÔ PHỎNG THỜI GIAN THỰC
     *
     * Quy trình mô phỏng:
     *
     * 1. KHỞI TẠO:
     *    - Tính số requests peak (60%) và normal (40%)
     *    - Thiết lập thời gian: 6:00 - 10:00 (4 giờ)
     *    - Khởi tạo các biến đếm và thống kê
     *
     * 2. VÒNG LẶP MÔ PHỎNG (mỗi bước = 1 phút):
     *    a) Xử lý checkin: Các reservation đã đến giờ và chưa hết hạn
     *    b) Xử lý expired: Các reservation quá hạn giữ chỗ (15 phút)
     *    c) Xử lý checkout: Các reservation đã đến end_time
     *    d) Tạo requests mới: Dựa trên thời gian hiện tại và phân bổ peak/normal
     *    e) Xử lý allocation: Cấp chỗ cho requests vừa tạo bằng thuật toán đã chọn
     *    f) Tăng thời gian: simulationTime += 1 phút
     *
     * 3. ĐIỀU KIỆN DỪNG:
     *    - Đã tạo đủ số requests (totalRequests)
     *    - Đã phân bổ đúng peak requests (60%) và normal requests (40%)
     *    - Đã đến thời gian kết thúc (10:00)
     *
     * 4. TÍNH TOÁN KẾT QUẢ:
     *    - Tỷ lệ thành công/thất bại
     *    - Tỷ lệ xung đột
     *    - Thời gian xử lý trung bình/tối đa
     *    - Độ sử dụng chỗ đỗ
     *    - Phân bổ theo giờ và theo loại xe
     *
     * 5. ĐÁNH GIÁ:
     *    - So sánh với yêu cầu: xung đột < 2%, thời gian < 1.5s
     *    - Hiển thị kết quả chi tiết
     *
     * @param array $algorithmNameMap Map tên thuật toán để hiển thị
     * @return void
     */
    private function runRealTimeSimulation($algorithmNameMap)
    {
        $this->info("🔧 Bắt đầu mô phỏng thời gian thực...\n");

        $peakRequests = intval($this->totalRequests * $this->peakRatio / 100);
        $normalRequests = $this->totalRequests - $peakRequests;

        $successCount = 0;
        $conflictCount = 0;
        $totalProcessingTime = 0;
        $processingTimes = [];
        $peakRequestCount = 0;
        $normalRequestCount = 0;
        $hourlyStats = [];

        $this->info("📊 Mô phỏng tập trung đánh giá hiệu quả thuật toán");
        $this->info("📊 Thời gian mô phỏng: " . $this->simulationTime->format('H:i') . " - " . $this->simulationTime->copy()->addHours(4)->format('H:i') . " (4 giờ)");
        $this->info("📊 Sẽ tạo {$this->totalRequests} requests trong khoảng thời gian này");
        $this->info("📊 Phân bổ: {$peakRequests} requests giờ cao điểm 7-9h (60%), {$normalRequests} requests giờ bình thường (40%)\n");

        // Lưu thời gian bắt đầu simulation để tính toán chính xác
        $simulationStartTime = $this->simulationTime->copy();

        // Tính thời gian kết thúc: bắt đầu từ 6:00, mô phỏng đến 10:00 (4 giờ)
        $simulationEndTime = $this->simulationTime->copy()->addHours(4); // 6:00 -> 10:00

        // Định nghĩa thời gian peak để sử dụng trong vòng lặp
        $peakStart = $simulationStartTime->copy()->setTime(7, 0);
        $peakEnd = $simulationStartTime->copy()->setTime(9, 0);

        $step = 0;
        $totalRequestsCreated = 0;
        $peakRequestCount = 0;
        $normalRequestCount = 0;

        // Chạy simulation theo thời gian thực: mỗi bước tạo requests dựa trên thời gian hiện tại
        // Đảm bảo tạo đủ số lượng requests và phân bổ chính xác
        while ($this->simulationTime->lessThan($simulationEndTime) && ($totalRequestsCreated < $this->totalRequests || $peakRequestCount < $peakRequests || $normalRequestCount < $normalRequests)) {
            $step++;
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("⏰ Bước {$step} - Thời gian mô phỏng: " . $this->simulationTime->format('H:i'));

            // Xử lý checkin các reservation đến giờ
            $this->processPendingCheckins();

            // Hủy các reservation đã quá thời gian giữ chỗ nhưng chưa checkin
            $this->processExpiredReservations();

            // Tính bước thời gian cho đợt này
            $dynamicStep = $this->getDynamicSimulationStep();

            // Bước 1: Xử lý checkout ngẫu nhiên (trước khi tạo request mới)
            $this->processRandomCheckouts();

            // Bước 2: Tạo requests mới dựa trên thời gian hiện tại
            // Đảm bảo phân bổ chính xác 60% peak, 40% normal
            $isPeak = $this->isPeakHour($this->simulationTime);

            // Hiển thị số slot available trước khi tạo requests (chỉ hiển thị tổng)
            $availableSlotsByType = $this->getAvailableSlotsByType();
            $totalAvailable = array_sum($availableSlotsByType);

            // Tính số requests còn lại cần tạo cho mỗi loại
            $remainingPeak = $peakRequests - $peakRequestCount;
            $remainingNormal = $normalRequests - $normalRequestCount;

            // QUAN TRỌNG: Chỉ tạo peak requests trong giờ peak (7-9h)
            // Chỉ tạo normal requests trong giờ normal (không phải 7-9h)
            // Điều này đảm bảo phân bổ chính xác 60% peak, 40% normal
            $shouldCreatePeak = false;
            if ($isPeak) {
                // Đang trong giờ peak: chỉ tạo peak requests nếu còn thiếu
                $shouldCreatePeak = ($remainingPeak > 0);
            } else {
                // Đang trong giờ normal: chỉ tạo normal requests nếu còn thiếu
                $shouldCreatePeak = false;
            }

            // Tính số requests tạo trong bước này
            $maxRequestsInStep = $this->calculateRequestsForCurrentTime(
                $isPeak,
                $totalRequestsCreated,
                $peakRequestCount,
                $normalRequestCount,
                $peakRequests,
                $normalRequests,
                $simulationStartTime,
                $simulationEndTime
            );
            $remainingTotal = $this->totalRequests - $totalRequestsCreated;
            $requestsInStep = min($maxRequestsInStep, $remainingTotal);

            // Điều chỉnh để đảm bảo không vượt quá số lượng còn lại của từng loại
            // QUAN TRỌNG: Ưu tiên tạo đủ số lượng yêu cầu
            if ($shouldCreatePeak && $remainingPeak > 0) {
                $requestsInStep = min($requestsInStep, $remainingPeak);
                // Nếu còn ít thời gian và còn nhiều requests, tăng số requests
                if ($remainingPeak > $requestsInStep && $this->simulationTime->greaterThanOrEqualTo($peakStart->copy()->addMinutes(110))) {
                    // Gần hết giờ peak, tăng số requests để đảm bảo tạo đủ
                    $requestsInStep = min($remainingPeak, $requestsInStep + 1);
                }
            } elseif (!$shouldCreatePeak && $remainingNormal > 0) {
                $requestsInStep = min($requestsInStep, $remainingNormal);
                // Nếu còn ít thời gian và còn nhiều requests, tăng số requests
                if ($remainingNormal > $requestsInStep && $this->simulationTime->greaterThanOrEqualTo($simulationEndTime->copy()->subMinutes(10))) {
                    // Gần hết simulation, tăng số requests để đảm bảo tạo đủ
                    $requestsInStep = min($remainingNormal, $requestsInStep + 1);
                }
            } else {
                // Không còn loại nào cần tạo
                $requestsInStep = 0;
            }

            if ($requestsInStep > 0 && $totalRequestsCreated < $this->totalRequests) {
                $this->info("   📝 Tạo {$requestsInStep} requests mới (" . ($shouldCreatePeak ? "Giờ cao điểm" : "Giờ bình thường") . ")...");

                $requests = $this->createRealTimeRequests($requestsInStep, $shouldCreatePeak);

                // Cập nhật số lượng peak/normal
                if ($shouldCreatePeak) {
                    $peakRequestCount += count($requests);
                } else {
                    $normalRequestCount += count($requests);
                }

                // Bước 3: Xử lý allocation ngay lập tức
                $batchResults = $this->processBatchAllocation($requests, $algorithmNameMap);

                // QUAN TRỌNG: Refresh requests từ database để lấy status mới nhất sau khi allocation
                $requestIds = collect($requests)->pluck('id')->toArray();
                $requests = ReservationRequest::whereIn('id', $requestIds)->get();

                $batchDistribution = $this->summarizeBatchRequests($requests);
                $this->updateHourlyStats($requests, $hourlyStats);

                // Cập nhật metrics
                $successCount += $batchResults['success'];
                $conflictCount += $batchResults['failed'];
                $totalProcessingTime += $batchResults['total_processing_time'];
                $processingTimes = array_merge($processingTimes, $batchResults['processing_times']);

                $totalRequestsCreated += count($requests);
                $this->logBatchMetrics($batchResults, $batchDistribution);
            }

            // Tăng thời gian mô phỏng
            $this->advanceSimulationTime($dynamicStep);

            // Đảm bảo không vượt quá thời gian kết thúc mô phỏng
            if ($this->simulationTime->greaterThanOrEqualTo($simulationEndTime)) {
                $this->simulationTime = $simulationEndTime->copy();
                $this->info("   ⏰ Đã đến thời gian kết thúc mô phỏng (" . $simulationEndTime->format('H:i') . ")");
            }

            $this->logOccupancySnapshot();

            // Delay nhỏ giữa các bước để mô phỏng thời gian thực
            usleep(100000); // 0.1 giây
        }

        // Đảm bảo checkin tất cả reservation đã đến giờ sau vòng lặp cuối
        $this->processPendingCheckins();
        $this->processExpiredReservations();

        // Tính kết quả cuối cùng từ database (đảm bảo chính xác)
        // Refresh lại từ database để lấy status mới nhất
        $allRequests = ReservationRequest::where('parking_lot_id', $this->parkingLot->id)->get();
        $actualSuccessCount = $allRequests->whereIn('status', ['assigned', 'completed'])->count();
        $actualFailedCount = $allRequests->where('status', 'failed')->count();

        // Đếm pending/cancelled không có reservation là failed
        $pendingFailed = $allRequests->whereIn('status', ['pending', 'cancelled'])
            ->filter(function ($request) {
                return !Reservation::where('reservation_request_id', $request->id)->exists();
            })
            ->count();

        $actualFailedCount += $pendingFailed;
        $actualSuccessCount = $allRequests->count() - $actualFailedCount;

        $avgProcessingTime = count($processingTimes) > 0 ? array_sum($processingTimes) / count($processingTimes) : 0;
        $maxProcessingTime = !empty($processingTimes) ? max($processingTimes) : 0;
        $successRate = $this->totalRequests > 0 ? ($actualSuccessCount / $this->totalRequests) * 100 : 0;
        $conflictRate = $this->totalRequests > 0 ? ($actualFailedCount / $this->totalRequests) * 100 : 0;
        $utilizationRate = $this->calculateUtilization();

        // Hiển thị kết quả (sử dụng số liệu thực tế từ database)
        $this->displayResults(
            $this->totalRequests,
            $actualSuccessCount,
            $actualFailedCount,
            $successRate,
            $conflictRate,
            $avgProcessingTime,
            $maxProcessingTime,
            $utilizationRate
        );
        $this->displayHourlyStats($hourlyStats);
        $this->displayVehicleTypeStats();

        // Đánh giá theo yêu cầu đề tài
        $this->evaluateResults($conflictRate, $avgProcessingTime);
    }

    /**
     * XỬ LÝ CHECKOUT TỰ ĐỘNG
     *
     * Mục đích: Mô phỏng quá trình xe ra khỏi bãi đỗ theo thời gian thực
     *
     * Logic checkout:
     *
     * 1. CHECKOUT ĐÚNG GIỜ:
     *    - Điều kiện: Reservation status = 'checked_in' AND end_time <= current_time
     *    - Tự động checkout khi đến đúng end_time đã đặt
     *    - check_out_at = end_time
     *
     * 2. CHECKOUT SỚM (mô phỏng thực tế bãi đỗ xe chung cư):
     *    - 15% xe checkout sớm 15-30 phút trước end_time
     *    - Điều kiện:
     *      * Reservation status = 'checked_in'
     *      * end_time trong vòng 30 phút tới
     *      * Đã checkin ít nhất 30 phút (tránh checkout ngay sau checkin)
     *    - Xác suất: 15% (earlyCheckoutProbability = 0.15)
     *    - Thời gian checkout sớm: end_time - (15-30 phút ngẫu nhiên)
     *
     * 3. SAU KHI CHECKOUT:
     *    - Reservation status → 'checked_out'
     *    - Slot status → 'available' (nếu không còn reservation active khác)
     *    - Giải phóng slot để có thể cấp cho request mới
     *
     * LƯU Ý: Checkout nhiều vào giờ cao điểm là BÌNH THƯỜNG vì:
     * - Giờ cao điểm có nhiều requests → nhiều reservations
     * - Nhiều reservations → nhiều checkout khi đến end_time
     * - Đây là hệ quả tự nhiên của phân bổ ngẫu nhiên, không phải lỗi
     *
     * @return void
     */
    private function processRandomCheckouts()
    {
        $currentTime = $this->simulationTime->copy()->utc();

        // Lấy các reservation đã đến thời gian checkout (end_time <= currentTime)
        // - Status = checked_in
        // - Đã checkin (có check_in_at)
        // - Đã đến thời gian kết thúc (end_time <= currentTime)
        $reservationsToCheckout = Reservation::where('status', 'checked_in')
            ->whereNotNull('check_in_at')
            ->where('end_time', '<=', $currentTime)
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->get();

        // Mô phỏng bãi đỗ xe chung cư TPHCM: 15% xe checkout sớm hơn dự kiến (15-30 phút)
        // Điều này phản ánh thực tế: người dùng có thể về sớm hoặc có việc đột xuất
        $earlyCheckoutProbability = 0.15; // 15% checkout sớm

        // Lấy các reservation sắp checkout (trong vòng 30 phút tới) để xét checkout sớm
        $reservationsNearCheckout = Reservation::where('status', 'checked_in')
            ->whereNotNull('check_in_at')
            ->where('end_time', '>', $currentTime)
            ->where('end_time', '<=', $currentTime->copy()->addMinutes(30))
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->get();

        $earlyCheckoutCount = 0;
        foreach ($reservationsNearCheckout as $reservation) {
            // 15% xác suất checkout sớm
            if (rand(1, 100) <= ($earlyCheckoutProbability * 100)) {
                // Checkout sớm: 15-30 phút trước end_time
                $earlyMinutes = rand(15, 30);
                $earlyCheckoutTime = $reservation->end_time->copy()->subMinutes($earlyMinutes);

                // Chỉ checkout sớm nếu đã checkin ít nhất 30 phút (tránh checkout ngay sau checkin)
                $minParkingDuration = 30;
                if (
                    $reservation->check_in_at &&
                    $reservation->check_in_at->diffInMinutes($earlyCheckoutTime) >= $minParkingDuration
                ) {

                    $reservation->update([
                        'status' => 'checked_out',
                        'check_out_at' => $earlyCheckoutTime,
                    ]);

                    // Slot trở lại available
                    $hasActiveReservation = Reservation::where('slot_id', $reservation->slot_id)
                        ->whereIn('status', ['confirmed', 'checked_in'])
                        ->where('id', '!=', $reservation->id)
                        ->exists();

                    if (!$hasActiveReservation) {
                        $reservation->slot->update(['status' => 'available']);
                    }

                    // Log checkout sớm
                    $startTime = $reservation->start_time->setTimezone($this->timezone)->format('H:i');
                    $endTime = $reservation->end_time->setTimezone($this->timezone)->format('H:i');
                    $checkoutTimeLocal = $earlyCheckoutTime->setTimezone($this->timezone)->format('H:i');
                    $checkinTime = $reservation->check_in_at ? $reservation->check_in_at->setTimezone($this->timezone)->format('H:i') : 'N/A';
                    $vehicleTypeName = $this->getVehicleTypeName($reservation->slot->vehicle_type);
                    $duration = $reservation->check_in_at ? $reservation->check_in_at->diffInMinutes($earlyCheckoutTime) : 0;
                    $earlyBy = $reservation->end_time->diffInMinutes($earlyCheckoutTime);

                    $this->info("   🚪 CHECKOUT SỚM: Reservation #{$reservation->id} | {$vehicleTypeName} | Slot: {$reservation->slot->slot_code} | Thời gian đỗ: {$startTime} - {$endTime} | Checkin: {$checkinTime} | Checkout: {$checkoutTimeLocal} (sớm {$earlyBy} phút) | Đã đỗ: {$duration} phút");

                    $earlyCheckoutCount++;
                }
            }
        }

        // Xử lý checkout đúng giờ (end_time <= currentTime)
        $checkoutCount = $reservationsToCheckout->count();

        if ($checkoutCount > 0) {
            foreach ($reservationsToCheckout as $reservation) {
                // Checkout: chuyển sang checked_out
                // Sử dụng end_time làm checkout time để đảm bảo đúng thời gian đã đặt
                $checkoutTime = $reservation->end_time->copy();

                $reservation->update([
                    'status' => 'checked_out',
                    'check_out_at' => $checkoutTime,
                ]);

                // Slot trở lại available (không còn reservation nào active)
                $hasActiveReservation = Reservation::where('slot_id', $reservation->slot_id)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where('id', '!=', $reservation->id)
                    ->exists();

                if (!$hasActiveReservation) {
                    $reservation->slot->update(['status' => 'available']);
                }

                // Log chi tiết checkout
                $startTime = $reservation->start_time->setTimezone($this->timezone)->format('H:i');
                $endTime = $reservation->end_time->setTimezone($this->timezone)->format('H:i');
                $checkoutTimeLocal = $checkoutTime->setTimezone($this->timezone)->format('H:i');
                $checkinTime = $reservation->check_in_at ? $reservation->check_in_at->setTimezone($this->timezone)->format('H:i') : 'N/A';
                $vehicleTypeName = $this->getVehicleTypeName($reservation->slot->vehicle_type);
                $duration = $reservation->check_in_at ? $reservation->check_in_at->diffInMinutes($checkoutTime) : 0;

                $this->info("   🚪 CHECKOUT: Reservation #{$reservation->id} | {$vehicleTypeName} | Slot: {$reservation->slot->slot_code} | Thời gian đỗ: {$startTime} - {$endTime} | Checkin: {$checkinTime} | Checkout: {$checkoutTimeLocal} | Đã đỗ: {$duration} phút");
            }
        }

        $totalCheckoutCount = $checkoutCount + $earlyCheckoutCount;
        if ($totalCheckoutCount > 0) {
            $this->info("   📊 Tổng cộng: {$totalCheckoutCount} xe đã checkout ({$checkoutCount} đúng giờ, {$earlyCheckoutCount} sớm)");
        }

        $this->lastCheckoutCount = $totalCheckoutCount;
    }

    /**
     * Tính số lượng requests tạo ở thời điểm hiện tại
     *
     * Logic: Phân bổ đều trong cả ngày để tránh tạo quá nhanh
     * - Tính toán riêng cho peak và normal dựa trên số requests còn lại và thời gian còn lại
     * - Giờ cao điểm (7-9h): tạo nhiều hơn một chút
     * - Giờ bình thường: tạo ít hơn
     * - Đảm bảo 300 requests được phân bổ đều trong 4 giờ (240 phút)
     */
    private function calculateRequestsForCurrentTime(
        $isPeak,
        $totalCreated,
        $peakRequestCount,
        $normalRequestCount,
        $peakRequests,
        $normalRequests,
        $simulationStartTime,
        $simulationEndTime
    ): int {
        // Tính số requests còn lại cho từng loại
        $remainingPeak = $peakRequests - $peakRequestCount;
        $remainingNormal = $normalRequests - $normalRequestCount;

        // Tính số phút còn lại cho từng loại
        $peakStart = $simulationStartTime->copy()->setTime(7, 0);
        $peakEnd = $simulationStartTime->copy()->setTime(9, 0);
        $peakTotalMinutes = $peakStart->diffInMinutes($peakEnd); // 120 phút

        if ($isPeak) {
            // Đang trong giờ peak: tính số phút peak còn lại
            $peakElapsed = max(0, $peakStart->diffInMinutes($this->simulationTime));
            $peakRemainingMinutes = max(1, $peakTotalMinutes - $peakElapsed);
            $remainingRequests = $remainingPeak;
            $remainingMinutesForType = $peakRemainingMinutes;
        } else {
            // Đang trong giờ normal: tính số phút normal còn lại
            // Normal = tổng thời gian - thời gian peak
            $totalSimulationMinutes = $simulationStartTime->diffInMinutes($simulationEndTime); // 240 phút
            $normalTotalMinutes = $totalSimulationMinutes - $peakTotalMinutes; // 120 phút

            // Tính số phút normal đã trôi qua
            $normalElapsed = 0;
            if ($this->simulationTime->lessThan($peakStart)) {
                // Trước giờ peak: tất cả thời gian đã trôi qua là normal
                $normalElapsed = $simulationStartTime->diffInMinutes($this->simulationTime);
            } elseif ($this->simulationTime->greaterThanOrEqualTo($peakEnd)) {
                // Sau giờ peak: thời gian normal = thời gian trước peak + thời gian sau peak
                $normalElapsed = $simulationStartTime->diffInMinutes($peakStart) + $peakEnd->diffInMinutes($this->simulationTime);
            }
            // Nếu đang trong peak (7-9h), không tính normal (sẽ không vào đây vì $isPeak = false)

            $remainingMinutesForType = max(1, $normalTotalMinutes - $normalElapsed);
            $remainingRequests = $remainingNormal;
        }

        // Nếu không còn requests hoặc không còn thời gian, không tạo thêm
        if ($remainingRequests <= 0 || $remainingMinutesForType <= 0) {
            return 0;
        }

        // Tính số requests trung bình mỗi phút còn lại cho loại này
        $avgRequestsPerMinute = $remainingRequests / $remainingMinutesForType;

        // MÔ PHỎNG THỰC TẾ: Không phải mỗi phút đều có request
        // - Giờ cao điểm: có thể có nhiều requests (0-5) trong một phút, hoặc không có trong vài phút
        // - Giờ bình thường: ít requests hơn (0-2), có thể không có trong nhiều phút
        // - Sử dụng phân phối xác suất để quyết định số requests mỗi phút

        $currentHour = (int) $this->simulationTime->format('H');
        $currentMinute = (int) $this->simulationTime->format('i');

        // Tính áp lực thời gian
        $timePressure = $remainingRequests / max(1, $remainingMinutesForType);

        // Xác định xác suất có request và số lượng requests có thể tạo
        if ($isPeak) {
            // Giờ cao điểm: xác suất cao hơn, có thể tạo nhiều requests
            // Xác suất có request: 60-80% (tùy áp lực thời gian)
            $requestProbability = min(0.8, 0.6 + ($timePressure * 0.2));
            // Số requests có thể tạo: 0-5 (tùy áp lực thời gian)
            $maxPossibleRequests = min(5, max(2, (int) ceil($timePressure * 3)));
        } else {
            // Giờ bình thường: xác suất thấp hơn, ít requests hơn
            if ($currentHour == 6 && $currentMinute < 30) {
                // Sáng sớm (6h-6h30): xác suất rất thấp
                $requestProbability = min(0.4, 0.2 + ($timePressure * 0.2));
                $maxPossibleRequests = min(2, max(1, (int) ceil($timePressure * 1.5)));
            } else {
                // Giờ bình thường khác: xác suất trung bình
                $requestProbability = min(0.6, 0.3 + ($timePressure * 0.3));
                $maxPossibleRequests = min(2, max(1, (int) ceil($timePressure * 2)));
            }
        }

        // Quyết định có tạo request trong phút này không
        $randomRequests = 0;
        if (rand(1, 100) <= ($requestProbability * 100)) {
            // Có tạo request: quyết định số lượng
            // Sử dụng phân phối để có nhiều requests hơn khi có áp lực thời gian
            if ($timePressure > 1.5) {
                // Áp lực cao: tạo nhiều requests hơn
                $randomRequests = rand(1, $maxPossibleRequests);
            } elseif ($timePressure > 1.0) {
                // Áp lực trung bình: tạo 1-3 requests
                $randomRequests = rand(1, min(3, $maxPossibleRequests));
            } else {
                // Áp lực thấp: tạo 1-2 requests
                $randomRequests = rand(1, min(2, $maxPossibleRequests));
            }
        }

        // Giới hạn không vượt quá số requests còn lại
        $randomRequests = min($randomRequests, $remainingRequests);

        // Điều chỉnh khi gần hết thời gian để đảm bảo tạo đủ
        // Nếu còn nhiều requests và ít thời gian, tăng xác suất và số lượng
        if ($remainingMinutesForType <= $remainingRequests && $randomRequests == 0) {
            // Bắt buộc phải tạo ít nhất 1 request
            $randomRequests = min(1, $remainingRequests);
        } elseif ($timePressure > 2.0 && $randomRequests == 0) {
            // Áp lực rất cao: bắt buộc tạo
            $randomRequests = min(2, $remainingRequests);
        }

        return $randomRequests;
    }

    /**
     * Lấy số slot available theo từng loại xe
     */
    private function getAvailableSlotsByType(): array
    {
        $availableSlots = [];
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];

        foreach ($vehicleTypes as $type) {
            $totalSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $type)
                ->count();

            // Đếm số slot có reservation active
            $occupiedSlots = Reservation::whereHas('slot', function ($q) use ($type) {
                $q->where('parking_lot_id', $this->parkingLot->id)
                    ->where('vehicle_type', $type);
            })
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->distinct('slot_id')
                ->count('slot_id');

            $availableSlots[$type] = max(0, $totalSlots - $occupiedSlots);
        }

        return $availableSlots;
    }

    /**
     * TẠO REQUESTS MÔ PHỎNG THEO THỜI GIAN THỰC
     *
     * Mục đích: Tạo N requests mới tại thời điểm hiện tại của simulation
     *
     * Đặc điểm mỗi request:
     * 1. desired_start_time = simulationTime (thời gian hiện tại)
     *    - Cho phép checkin ngay khi được cấp chỗ
     *    - Không đặt trước, phản ánh thực tế người dùng đặt chỗ khi đến bãi
     *
     * 2. duration_minutes:
     *    - Peak requests (giờ cao điểm): 4-10 giờ (240-600 phút) - đi làm cả ngày
     *    - Normal requests: 1-6 giờ (60-360 phút) - đi chợ, thăm bạn
     *    - Tất cả làm tròn về bước nhảy 30 phút
     *
     * 3. vehicle_type:
     *    - Phân bổ ngẫu nhiên theo tỷ lệ slot thực tế trong bãi
     *    - Không điều chỉnh dựa trên slot available (phản ánh thực tế người dùng chọn loại xe)
     *
     * 4. gate_id:
     *    - Chọn cổng ngẫu nhiên (người dùng chọn cổng vào)
     *    - Thuật toán sẽ ưu tiên slot gần cổng này
     *
     * 5. status = 'pending':
     *    - Sẽ được xử lý allocation ngay sau khi tạo
     *
     * @param int $count Số lượng requests cần tạo
     * @param bool $isPeak true nếu là giờ cao điểm (7-9h), false nếu giờ bình thường
     * @return array Mảng các ReservationRequest đã tạo
     */
    private function createRealTimeRequests($count, $isPeak): array
    {
        $requests = [];

        // Phân bổ loại xe dựa trên tỷ lệ slot thực tế trong bãi
        $vehicleTypeDistribution = $this->getVehicleTypeDistributionFromSlots();

        for ($i = 0; $i < $count; $i++) {
            // QUAN TRỌNG: Đặt desired_start_time = thời gian hiện tại để có thể checkin ngay
            // Phân loại peak/normal dựa trên thời gian hiện tại, không phải desired_start_time
            $startTime = $this->simulationTime->copy();

            if ($isPeak) {
                // Peak request: thời gian đỗ thường 4-10 giờ (đi làm cả ngày) - bước nhảy 30 phút
                $duration = $this->roundTo30Minutes(rand(4, 10) * 60); // 240, 270, 300, ..., 600 phút
            } else {
                // Normal: thời gian đỗ 1-6 giờ (đi chợ, thăm bạn, etc.) - bước nhảy 30 phút
                $duration = $this->roundTo30Minutes(rand(1, 6) * 60); // 60, 90, 120, ..., 360 phút
            }

            // Giới hạn end_time không vượt quá 23:59:59
            $todayEnd = $startTime->copy()->endOfDay();
            $endTime = $startTime->copy()->addMinutes($duration);

            if ($endTime > $todayEnd) {
                $maxDuration = $startTime->diffInMinutes($todayEnd);
                if ($maxDuration > 0) {
                    // Làm tròn maxDuration về bước nhảy 30 phút trước khi so sánh
                    $maxDuration = $this->roundTo30Minutes($maxDuration);
                    $duration = min($duration, $maxDuration);
                    $endTime = $startTime->copy()->addMinutes($duration);
                } else {
                    continue;
                }
            }

            // Chọn loại xe theo phân bổ thực tế, nhưng ưu tiên loại có nhiều slot available
            $availableSlotsByType = $this->getAvailableSlotsByType();

            // Phân bổ loại xe ngẫu nhiên dựa trên tỷ lệ slot thực tế trong bãi
            // KHÔNG điều chỉnh dựa trên slot available để phản ánh thực tế:
            // Người dùng vẫn chọn loại xe ngẫu nhiên bất kể có bao nhiêu slot available
            $vehicleType = $this->selectVehicleType($vehicleTypeDistribution);

            // Chọn cổng ngẫu nhiên (người dùng chọn cổng)
            $selectedGate = $this->gates->random();

            // Đảm bảo desired_start_time được lưu đúng timezone (UTC)
            $request = ReservationRequest::create([
                'parking_lot_id' => $this->parkingLot->id,
                'gate_id' => $selectedGate->id,
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime->copy()->utc(), // Chuyển về UTC để nhất quán với DB
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => $this->simulationTime->copy()->utc()
            ]);

            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Tạo batch requests mới với phân bổ chính xác 60% giờ cao điểm
     * Phân bổ loại xe đa dạng phù hợp thực tế chung cư Việt Nam
     */
    private function createBatchRequests($count, &$peakCount, &$normalCount, $peakTotal, $normalTotal)
    {
        $requests = [];
        $peakHours = [
            ['start' => '07:00', 'end' => '09:00'],   // Sáng: 7h-9h
            ['start' => '17:30', 'end' => '19:30']    // Chiều: 17h30-19h30
        ];

        // Phân bổ loại xe dựa trên tỷ lệ slot thực tế trong bãi
        $vehicleTypeDistribution = $this->getVehicleTypeDistributionFromSlots();

        // Tính số lượng còn lại cần tạo cho mỗi loại
        $remainingPeak = $peakTotal - $peakCount;
        $remainingNormal = $normalTotal - $normalCount;
        $remainingInBatch = $count;

        // Đảm bảo phân bổ chính xác: ưu tiên đạt đúng 60% peak
        for ($i = 0; $i < $count; $i++) {
            $isPeak = false;

            // Logic phân bổ: đảm bảo đạt đúng 60% peak
            if ($remainingPeak > 0 && $remainingNormal > 0) {
                // Còn cả peak và normal, quyết định dựa trên tỷ lệ
                $peakRatio = $remainingPeak / ($remainingPeak + $remainingNormal);
                $isPeak = (mt_rand() / mt_getrandmax()) <= $peakRatio;
            } elseif ($remainingPeak > 0) {
                // Chỉ còn peak
                $isPeak = true;
            } elseif ($remainingNormal > 0) {
                // Chỉ còn normal
                $isPeak = false;
            } else {
                // Đã đủ cả hai, không tạo thêm
                break;
            }

            $startTime = null;
            $duration = 0;

            if ($isPeak && $remainingPeak > 0) {
                // Peak hour request - thời gian đỗ thường dài hơn (đi làm)
                $peakHour = $peakHours[array_rand($peakHours)];
                $startTime = $this->randomTimeInRange($peakHour['start'], $peakHour['end']);

                if (!$startTime) {
                    // Khung giờ đã qua, chuyển sang normal
                    $startTime = $this->randomNormalHour();
                    if (!$startTime) {
                        continue;
                    }
                    $duration = $this->roundTo30Minutes(rand(2, 6) * 60); // 2-6 giờ cho normal (bước nhảy 30 phút)
                    $normalCount++;
                    $remainingNormal--;
                } else {
                    // Peak hour: thời gian đỗ thường 4-10 giờ (đi làm cả ngày) - bước nhảy 30 phút
                    $duration = $this->roundTo30Minutes(rand(4, 10) * 60);
                    $peakCount++;
                    $remainingPeak--;
                }
            } else {
                // Normal hour request - thời gian đỗ ngắn hơn
                $startTime = $this->randomNormalHour();
                if (!$startTime) {
                    continue;
                }
                // Normal: thời gian đỗ 1-6 giờ (đi chợ, thăm bạn, etc.) - bước nhảy 30 phút
                $duration = $this->roundTo30Minutes(rand(1, 6) * 60);
                $normalCount++;
                $remainingNormal--;
            }

            // Giới hạn end_time không vượt quá 23:59:59
            $todayEnd = $startTime->copy()->endOfDay();
            $endTime = $startTime->copy()->addMinutes($duration);

            // Đảm bảo duration là bước nhảy 30 phút sau khi điều chỉnh
            if ($endTime > $todayEnd) {
                $maxDuration = $startTime->diffInMinutes($todayEnd);
                if ($maxDuration > 0) {
                    $maxDuration = $this->roundTo30Minutes($maxDuration);
                    $duration = min($duration, $maxDuration);
                    $endTime = $startTime->copy()->addMinutes($duration);
                } else {
                    continue;
                }
            }

            if ($endTime > $todayEnd) {
                $maxDuration = $startTime->diffInMinutes($todayEnd);
                if ($maxDuration > 0) {
                    $duration = min($duration, $maxDuration);
                } else {
                    continue;
                }
            }

            // Chọn loại xe theo phân bổ thực tế
            $vehicleType = $this->selectVehicleType($vehicleTypeDistribution);

            // Chọn cổng ngẫu nhiên (người dùng chọn cổng)
            $selectedGate = $this->gates->random();

            // Đảm bảo desired_start_time được lưu đúng timezone (UTC)
            $request = ReservationRequest::create([
                'parking_lot_id' => $this->parkingLot->id,
                'gate_id' => $selectedGate->id,
                'vehicle_type' => $vehicleType,
                'desired_start_time' => $startTime->copy()->utc(), // Chuyển về UTC để nhất quán với DB
                'duration_minutes' => $duration,
                'status' => 'pending',
                'requested_at' => $this->simulationTime->copy()->utc()
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

                    // Log chi tiết request thành công
                    $startTime = $request->desired_start_time->format('H:i');
                    $endTime = $request->desired_start_time->copy()->addMinutes($request->duration_minutes)->format('H:i');
                    $vehicleTypeName = $this->getVehicleTypeName($request->vehicle_type);

                    $this->info("   ✅ Request #{$request->id}: {$vehicleTypeName} | Slot: {$allocatedSlot->slot_code} | Thời gian: {$startTime} - {$endTime} ({$request->duration_minutes} phút) | Trạng thái: confirmed");
                } else {
                    $failed++;
                    // Debug: Log chi tiết request failed với thông tin slot
                    $totalSlots = \App\Models\ParkingSlot::where('parking_lot_id', $request->parking_lot_id)
                        ->where('vehicle_type', $request->vehicle_type)
                        ->count();

                    $conflictingSlots = \App\Models\Reservation::whereHas('slot', function ($q) use ($request) {
                        $q->where('parking_lot_id', $request->parking_lot_id)
                            ->where('vehicle_type', $request->vehicle_type);
                    })
                        ->whereIn('status', ['confirmed', 'checked_in'])
                        ->where(function ($q) use ($request) {
                            $parkingStart = $request->desired_start_time->copy()->utc();
                            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes)->utc();
                            $q->where('end_time', '>', $parkingStart)
                                ->where('start_time', '<', $parkingEnd);
                        })
                        ->distinct('slot_id')
                        ->count('slot_id');

                    $availableSlots = $totalSlots - $conflictingSlots;

                    $startTime = $request->desired_start_time->format('H:i');
                    $endTime = $request->desired_start_time->copy()->addMinutes($request->duration_minutes)->format('H:i');
                    $vehicleTypeName = $this->getVehicleTypeName($request->vehicle_type);

                    $this->warn("   ❌ Request #{$request->id} FAILED: {$vehicleTypeName} | Thời gian: {$startTime} - {$endTime} ({$request->duration_minutes} phút) | Available slots: {$availableSlots}/{$totalSlots}");
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
                    $this->createReservation($request, $slot);
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
        ];
    }

    /**
     * XỬ LÝ CHECKIN TỰ ĐỘNG
     *
     * Mục đích: Mô phỏng quá trình xe vào bãi đỗ khi đến giờ đã đặt
     *
     * Điều kiện checkin:
     * 1. Reservation status = 'confirmed' (đã được cấp chỗ)
     * 2. start_time <= current_time (đã đến giờ đặt)
     * 3. expires_at >= current_time (chưa hết hạn giữ chỗ 15 phút) - QUAN TRỌNG!
     * 4. check_in_at = null (chưa checkin)
     *
     * Quy trình checkin:
     * - Reservation status → 'checked_in'
     * - Slot status → 'occupied' (chuyển từ 'available' hoặc 'hold' logic)
     * - check_in_at = max(start_time, current_time)
     *
     * Lưu ý:
     * - Nếu start_time trong quá khứ, checkin ngay tại current_time
     * - Nếu start_time trong tương lai, đợi đến start_time mới checkin
     * - Nếu quá hạn giữ chỗ (expires_at < current_time), không checkin (sẽ bị expired)
     *
     * @return void
     */
    private function processPendingCheckins()
    {
        $currentTime = $this->simulationTime->copy()->utc();

        // Chỉ checkin các reservation:
        // - Đã đến giờ (start_time <= currentTime)
        // - Chưa hết hạn giữ chỗ (expires_at >= currentTime) - QUAN TRỌNG!
        // - Chưa checkin (check_in_at is null)
        $readyReservations = Reservation::where('status', 'confirmed')
            ->where('start_time', '<=', $currentTime)
            ->where('expires_at', '>=', $currentTime) // Chưa hết hạn giữ chỗ
            ->whereNull('check_in_at')
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->get();

        foreach ($readyReservations as $reservation) {
            // Checkin ngay khi đến giờ (start_time), không đợi đến simulation time
            // Nhưng đảm bảo không quá expires_at
            $checkinTime = max($reservation->start_time->copy(), $currentTime->copy());

            $reservation->update([
                'status' => 'checked_in',
                'check_in_at' => $checkinTime,
            ]);

            // Slot chuyển từ 'hold' sang 'occupied' khi checked_in
            $reservation->slot->update(['status' => 'occupied']);

            // Log chi tiết checkin
            $startTime = $reservation->start_time->setTimezone($this->timezone)->format('H:i');
            $endTime = $reservation->end_time->setTimezone($this->timezone)->format('H:i');
            $expiresAt = $reservation->expires_at->setTimezone($this->timezone)->format('H:i');
            $checkinTimeLocal = $checkinTime->setTimezone($this->timezone)->format('H:i');
            $vehicleTypeName = $this->getVehicleTypeName($reservation->slot->vehicle_type);

            $this->info("   🚗 CHECKIN: Reservation #{$reservation->id} | {$vehicleTypeName} | Slot: {$reservation->slot->slot_code} | Thời gian đỗ: {$startTime} - {$endTime} | Hết hạn: {$expiresAt} | Checkin lúc: {$checkinTimeLocal}");
        }

        $checkedInCount = $readyReservations->count();
        if ($checkedInCount > 0) {
            $this->info("   📊 Tổng cộng: {$checkedInCount} xe đã checkin");
        }

        $this->lastCheckinCount = $checkedInCount;
    }

    /**
     * Hủy các reservation quá hạn giữ chỗ (15 phút) mà chưa checkin
     */
    private function processExpiredReservations()
    {
        $currentTime = $this->simulationTime->copy()->utc();

        $expiredReservations = Reservation::where('status', 'confirmed')
            ->whereNull('check_in_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $currentTime)
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->get();

        if ($expiredReservations->isEmpty()) {
            return;
        }

        foreach ($expiredReservations as $reservation) {
            $reservation->update([
                'status' => 'expired',
                'cancelled_at' => $currentTime,
            ]);

            if ($reservation->slot) {
                // Kiểm tra xem slot còn reservation active khác không
                $hasActiveReservation = Reservation::where('slot_id', $reservation->slot_id)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where('id', '!=', $reservation->id)
                    ->exists();

                if (!$hasActiveReservation) {
                    $reservation->slot->update(['status' => 'available']);
                }
            }

            if ($reservation->reservation_request_id && $reservation->reservationRequest) {
                /**
                 * LOGIC XỬ LÝ RESERVATION_REQUEST KHI RESERVATION EXPIRED:
                 *
                 * ReservationRequest chỉ có vai trò: TIẾP NHẬN YÊU CẦU và ASSIGN SLOT
                 *
                 * Trường hợp 1: Request status = 'pending' (chưa được assign slot)
                 *   → Reservation expired nhưng chưa có slot → Request THẤT BẠI
                 *   → Set status = 'failed'
                 *
                 * Trường hợp 2: Request status = 'assigned' (đã được assign slot thành công)
                 *   → Reservation expired nhưng request ĐÃ HOÀN THÀNH vai trò (đã assign slot)
                 *   → Set status = 'completed' (đánh dấu request đã hoàn thành nhiệm vụ)
                 *   → Lưu ý: Reservation expired là vấn đề của Reservation, không phải Request
                 *
                 * Trường hợp 3: User hủy (xử lý ở chỗ khác)
                 *   → Set status = 'cancelled'
                 */
                if ($reservation->reservationRequest->status === 'pending') {
                    // Request chưa assign được slot → thất bại
                    $reservation->reservationRequest->update(['status' => 'failed']);
                } elseif ($reservation->reservationRequest->status === 'assigned') {
                    // Request đã assign slot thành công → hoàn thành vai trò
                    $reservation->reservationRequest->update(['status' => 'completed']);
                }
            }
        }

        $this->warn("   ⛔ {$expiredReservations->count()} reservation quá hạn giữ chỗ đã bị hủy");
    }

    /**
     * Tóm tắt số request peak/normal và số conflict trong 1 batch
     * Lưu ý: Peak/normal được xác định bởi tham số $isPeak khi tạo request,
     * không phải dựa trên desired_start_time (vì desired_start_time = simulationTime)
     */
    private function summarizeBatchRequests($requests): array
    {
        $stats = [
            'peak_total' => 0,
            'normal_total' => 0,
            'peak_conflict' => 0,
            'normal_conflict' => 0,
        ];

        foreach ($requests as $request) {
            // Xác định peak/normal dựa trên desired_start_time (thời gian đặt)
            // Nếu desired_start_time trong giờ cao điểm (7-9h) → peak
            $group = $this->isPeakHour($request->desired_start_time) ? 'peak' : 'normal';
            $stats["{$group}_total"]++;

            if ($request->status === 'failed') {
                $stats["{$group}_conflict"]++;
            }
        }

        return $stats;
    }

    /**
     * Log kết quả mỗi batch, phân biệt peak/normal
     */
    private function logBatchMetrics(array $batchResults, array $distribution): void
    {
        $this->info("   ✅ Thành công: {$batchResults['success']}, ❌ Xung đột: {$batchResults['failed']}");
        $this->info("   ⏱️  Thời gian xử lý TB: " . round($batchResults['avg_processing_time'], 2) . "ms");
        $this->info(sprintf(
            "   📈 Peak: %d requests (xung đột %d) | Normal: %d requests (xung đột %d) | Xe vào: %d | Xe ra: %d",
            $distribution['peak_total'],
            $distribution['peak_conflict'],
            $distribution['normal_total'],
            $distribution['normal_conflict'],
            $this->lastCheckinCount,
            $this->lastCheckoutCount
        ));
        $this->info("");
    }

    /**
     * Cập nhật thống kê theo từng giờ
     */
    private function updateHourlyStats($requests, array &$hourlyStats): void
    {
        foreach ($requests as $request) {
            $localTime = $request->desired_start_time->copy()->setTimezone($this->timezone);
            $hourKey = $localTime->format('H:00');
            $group = $this->isPeakHour($request->desired_start_time) ? 'peak' : 'normal';

            if (!isset($hourlyStats[$hourKey])) {
                $hourlyStats[$hourKey] = [
                    'total' => 0,
                    'conflict' => 0,
                    'peak_total' => 0,
                    'peak_conflict' => 0,
                    'normal_total' => 0,
                    'normal_conflict' => 0,
                ];
            }

            $hourlyStats[$hourKey]['total']++;
            $hourlyStats[$hourKey]["{$group}_total"]++;

            if ($request->status === 'failed') {
                $hourlyStats[$hourKey]['conflict']++;
                $hourlyStats[$hourKey]["{$group}_conflict"]++;
            }
        }
    }

    /**
     * Hiển thị bảng phân bổ request theo từng giờ
     */
    private function displayHourlyStats(array $hourlyStats): void
    {
        if (empty($hourlyStats)) {
            return;
        }

        ksort($hourlyStats);

        $this->info("\n📈 PHÂN BỐ REQUEST THEO GIỜ:");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info(sprintf("%-8s | %-8s | %-8s | %-20s | %-8s | %-20s", "Giờ", "Tổng", "Peak", "Peak (xung đột)", "Normal", "Normal (xung đột)"));
        $this->info("───────────────────────────────────────────────────────────────");

        $totalPeak = 0;
        $totalPeakConflict = 0;
        $totalNormal = 0;
        $totalNormalConflict = 0;

        foreach ($hourlyStats as $hour => $data) {
            $peakRate = $data['peak_total'] > 0
                ? round(($data['peak_conflict'] / $data['peak_total']) * 100, 1)
                : 0;
            $normalRate = $data['normal_total'] > 0
                ? round(($data['normal_conflict'] / $data['normal_total']) * 100, 1)
                : 0;

            // Format: "Giờ | Tổng | Peak (tổng) | Peak (xung đột - tỷ lệ%) | Normal (tổng) | Normal (xung đột - tỷ lệ%)"
            $this->info(sprintf(
                "%-8s | %-8d | %-8d | %-2d (%5.1f%%) | %-8d | %-2d (%5.1f%%)",
                $hour,
                $data['total'],
                $data['peak_total'],      // Tổng requests peak
                $data['peak_conflict'],   // Số xung đột peak
                $peakRate,                // Tỷ lệ xung đột peak
                $data['normal_total'],    // Tổng requests normal
                $data['normal_conflict'], // Số xung đột normal
                $normalRate               // Tỷ lệ xung đột normal
            ));

            $totalPeak += $data['peak_total'];
            $totalPeakConflict += $data['peak_conflict'];
            $totalNormal += $data['normal_total'];
            $totalNormalConflict += $data['normal_conflict'];
        }

        $this->info("───────────────────────────────────────────────────────────────");
        $totalPeakRate = $totalPeak > 0 ? round(($totalPeakConflict / $totalPeak) * 100, 2) : 0;
        $totalNormalRate = $totalNormal > 0 ? round(($totalNormalConflict / $totalNormal) * 100, 2) : 0;

        // Format: "TỔNG | Tổng | Peak (tổng) | Peak (xung đột - tỷ lệ%) | Normal (tổng) | Normal (xung đột - tỷ lệ%)"
        $this->info(sprintf(
            "TỔNG     | %-8d | %-8d | %-2d (%5.2f%%) | %-8d | %-2d (%5.2f%%)",
            $totalPeak + $totalNormal,
            $totalPeak,           // Tổng requests peak
            $totalPeakConflict,   // Số xung đột peak
            $totalPeakRate,       // Tỷ lệ xung đột peak
            $totalNormal,         // Tổng requests normal
            $totalNormalConflict, // Số xung đột normal
            $totalNormalRate      // Tỷ lệ xung đột normal
        ));
        $this->info("═══════════════════════════════════════════════════════════════");

        // Kiểm tra phân bổ 60% peak
        $peakPercentage = round(($totalPeak / ($totalPeak + $totalNormal)) * 100, 1);
        $this->info("📊 Phân bổ giờ cao điểm: {$peakPercentage}% (mục tiêu: 60%)");
        if (abs($peakPercentage - 60) > 5) {
            $this->warn("⚠️ Phân bổ giờ cao điểm chênh lệch > 5% so với mục tiêu");
        }
    }

    /**
     * Log nhanh số slot đang chiếm dụng vs trống
     */
    private function logOccupancySnapshot(): void
    {
        $totalSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)->count();

        $checkedInSlots = Reservation::where('status', 'checked_in')
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->distinct('slot_id')
            ->count('slot_id');

        $confirmedSlots = Reservation::where('status', 'confirmed')
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->distinct('slot_id')
            ->count('slot_id');

        $engagedSlots = Reservation::whereIn('status', ['checked_in', 'confirmed'])
            ->whereHas('slot', function ($q) {
                $q->where('parking_lot_id', $this->parkingLot->id);
            })
            ->distinct('slot_id')
            ->count('slot_id');

        $availableSlots = max(0, $totalSlots - $engagedSlots);

        $this->info(sprintf(
            "   🅿️ Tình trạng bãi: %d/%d slot dùng (đỗ: %d, giữ: %d) | Trống: %d",
            $engagedSlots,
            $totalSlots,
            $checkedInSlots,
            $confirmedSlots,
            $availableSlots
        ));

        // Hiển thị số slot trống theo từng loại xe
        $availableSlotsByType = $this->getAvailableSlotsByType();
        $typeNames = [
            'motorbike' => 'Xe máy',
            'car_4_seat' => 'Ô tô 4 chỗ',
            'car_7_seat' => 'Ô tô 7 chỗ',
            'light_truck' => 'Xe tải nhẹ',
        ];

        $availableDetails = [];
        foreach ($availableSlotsByType as $type => $count) {
            $totalForType = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $type)
                ->count();
            $availableDetails[] = "{$typeNames[$type]}: {$count}/{$totalForType}";
        }

        $this->info("   📊 Slot trống theo loại: " . implode(" | ", $availableDetails));
    }

    /**
     * Kiểm tra thời điểm có thuộc khung giờ cao điểm hay không
     */
    private function isPeakHour(Carbon $time): bool
    {
        $localTime = $time->copy()->setTimezone($this->timezone);
        $minutes = $localTime->hour * 60 + $localTime->minute;

        $morningStart = 7 * 60;
        $morningEnd = 9 * 60;
        $eveningStart = 17 * 60 + 30;
        $eveningEnd = 19 * 60 + 30;

        return ($minutes >= $morningStart && $minutes < $morningEnd)
            || ($minutes >= $eveningStart && $minutes < $eveningEnd);
    }

    /**
     * Tạo reservation từ request và slot
     */
    private function createReservation($request, $slot)
    {
        $startTimeLocal = $request->desired_start_time->copy();
        $endTimeLocal = $startTimeLocal->copy()->addMinutes($request->duration_minutes);

        // ✅ Giới hạn end_time không vượt quá 23:59:59 của ngày start_time
        $dayEndLocal = $startTimeLocal->copy()->endOfDay();
        if ($endTimeLocal > $dayEndLocal) {
            $endTimeLocal = $dayEndLocal->copy();
        }

        $expiresAtLocal = $startTimeLocal->copy()->addMinutes(15);
        if ($expiresAtLocal > $dayEndLocal) {
            $expiresAtLocal = $dayEndLocal->copy();
        }

        $reservation = Reservation::create([
            'user_id' => null,
            'vehicle_id' => null,
            'slot_id' => $slot->id,
            'reservation_request_id' => $request->id,
            'reservation_code' => 'RES-' . strtoupper(uniqid()) . '-' . now()->format('Ymd'),
            'status' => 'confirmed',
            'start_time' => $startTimeLocal->copy()->utc(),
            'end_time' => $endTimeLocal->copy()->utc(),
            'expires_at' => $expiresAtLocal->copy()->utc(),
        ]);

        // Slot status không thay đổi khi có reservation confirmed
        // Trạng thái 'hold' là logic: slot có reservation với status = 'confirmed' được coi là 'hold'
        // Slot chỉ có 2 trạng thái trong DB: 'available' và 'occupied'
        // Khi checked_in, slot mới chuyển sang 'occupied'

        return $reservation;
    }

    /**
     * Làm tròn duration về bước nhảy 30 phút
     * Ví dụ: 31 phút → 30, 45 phút → 30, 60 phút → 60, 90 phút → 90
     */
    private function roundTo30Minutes(int $minutes): int
    {
        return round($minutes / 30) * 30;
    }

    /**
     * Lấy tên loại xe
     */
    private function getVehicleTypeName($vehicleType): string
    {
        $names = [
            'motorbike' => 'Xe máy',
            'car_4_seat' => 'Ô tô 4 chỗ',
            'car_7_seat' => 'Ô tô 7 chỗ',
            'light_truck' => 'Xe tải nhẹ',
        ];

        return $names[$vehicleType] ?? $vehicleType;
    }

    /**
     * Lấy tỷ lệ phân bổ loại xe dựa trên số lượng slot thực tế trong bãi
     */
    private function getVehicleTypeDistributionFromSlots(): array
    {
        // Kiểm tra parkingLot đã được khởi tạo chưa
        if (!$this->parkingLot || !$this->parkingLot->id) {
            // Fallback nếu chưa có parkingLot
            return [
                'motorbike' => 0.50,
                'car_4_seat' => 0.35,
                'car_7_seat' => 0.12,
                'light_truck' => 0.03,
            ];
        }

        $totalSlots = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)->count();

        if ($totalSlots == 0) {
            // Fallback nếu không có slot
            return [
                'motorbike' => 0.50,
                'car_4_seat' => 0.35,
                'car_7_seat' => 0.12,
                'light_truck' => 0.03,
            ];
        }

        $distribution = [];
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];

        foreach ($vehicleTypes as $type) {
            $count = ParkingSlot::where('parking_lot_id', $this->parkingLot->id)
                ->where('vehicle_type', $type)
                ->count();
            $distribution[$type] = $count / $totalSlots;
        }

        return $distribution;
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
        $today = $this->simulationTime->copy()->startOfDay();
        $start = $today->copy()->setTimeFromTimeString($startTime);
        $end = $today->copy()->setTimeFromTimeString($endTime);

        // Nếu thời gian mô phỏng đã vượt quá khung giờ hiện tại
        // Vẫn tạo request trong khung giờ đó (để mô phỏng đầy đủ cả ngày)
        // Nhưng đảm bảo không tạo request trong quá khứ
        if ($this->simulationTime->greaterThanOrEqualTo($end)) {
            // Khung giờ đã qua, không tạo request trong quá khứ
            return null;
        }

        // Nếu đang ở trong khung giờ, đảm bảo start không trước simulationTime
        if ($this->simulationTime->greaterThan($start) && $this->simulationTime->lessThan($end)) {
            $start = $this->simulationTime->copy();
        }

        if ($start >= $end) {
            return null;
        }

        $randomMinutes = rand(0, max(1, $start->diffInMinutes($end)));
        return $start->copy()->addMinutes($randomMinutes);
    }

    /**
     * Random giờ bình thường (phân bổ trong khoảng mô phỏng: 6:00-10:00)
     * Tập trung để đánh giá hiệu quả thuật toán, không cần mô phỏng cả ngày
     */
    private function randomNormalHour()
    {
        $today = $this->simulationTime->copy()->startOfDay();
        $currentHour = $this->simulationTime->hour;
        $currentMinute = $this->simulationTime->minute;

        // Random giờ trong khoảng mô phỏng: 6:00-10:00
        // Đảm bảo không tạo request trong quá khứ
        $minHour = max(6, $currentHour); // Tối thiểu từ 6h hoặc giờ hiện tại
        $maxHour = min(10, 23); // Tối đa đến 10h (kết thúc mô phỏng)

        if ($minHour > $maxHour) {
            // Đã vượt quá thời gian mô phỏng
            return null;
        }

        $hour = rand($minHour, $maxHour);

        // Tránh giờ cao điểm: 7-9h sáng (chỉ trong khoảng mô phỏng)
        $attempts = 0;
        while ($hour >= 7 && $hour < 9) {
            $hour = rand($minHour, $maxHour);
            $attempts++;
            // Nếu thử quá nhiều lần, chấp nhận giờ cao điểm
            if ($attempts > 10) {
                break;
            }
        }

        // Nếu là giờ hiện tại, minute phải >= minute hiện tại
        // Nếu là giờ tương lai, random minute bất kỳ
        if ($hour == $currentHour) {
            $minute = rand($currentMinute, 59);
        } else {
            $minute = rand(0, 59);
        }

        return $today->copy()->setHour($hour)->setMinute($minute)->setSecond(0);
    }

    /**
     * Tăng thời gian mô phỏng để mô phỏng realtime
     */
    private function advanceSimulationTime($minutes = null)
    {
        $minutes = $minutes ?? 1; // Mặc định 1 phút
        $minutes = max(1, $minutes);
        $this->simulationTime->addMinutes($minutes);
        $this->minParkingDurationMinutes = max(15, $minutes);
        $this->info("   ⏩ Thời gian mô phỏng hiện tại: " . $this->simulationTime->format('H:i') . " (+= {$minutes}p)");
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
     * Hiển thị thống kê phân bổ loại xe
     */
    private function displayVehicleTypeStats(): void
    {
        $requests = ReservationRequest::where('parking_lot_id', $this->parkingLot->id)->get();

        $stats = [
            'motorbike' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'car_4_seat' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'car_7_seat' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'light_truck' => ['total' => 0, 'success' => 0, 'failed' => 0],
        ];

        foreach ($requests as $request) {
            $type = $request->vehicle_type;
            if (isset($stats[$type])) {
                $stats[$type]['total']++;
                // Success = đã được assign slot (assigned hoặc completed)
                // Failed = không được assign slot (failed hoặc pending nhưng không có reservation)
                if (in_array($request->status, ['assigned', 'completed'])) {
                    $stats[$type]['success']++;
                } elseif ($request->status === 'failed') {
                    $stats[$type]['failed']++;
                } else {
                    // pending, cancelled - kiểm tra xem có reservation không
                    $hasReservation = Reservation::where('reservation_request_id', $request->id)->exists();
                    if ($hasReservation) {
                        $stats[$type]['success']++; // Có reservation = đã được assign
                    } else {
                        $stats[$type]['failed']++; // Không có reservation = thất bại
                    }
                }
            }
        }

        $this->info("\n🚗 PHÂN BỐ LOẠI XE:");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info(sprintf("%-15s | %-8s | %-8s | %-8s | %-10s", "Loại xe", "Tổng", "Thành công", "Thất bại", "Tỷ lệ (%)"));
        $this->info("───────────────────────────────────────────────────────────────");

        $typeNames = [
            'motorbike' => 'Xe máy',
            'car_4_seat' => 'Ô tô 4 chỗ',
            'car_7_seat' => 'Ô tô 7 chỗ',
            'light_truck' => 'Xe tải nhẹ',
        ];

        foreach ($stats as $type => $data) {
            $percentage = $data['total'] > 0 ? round(($data['total'] / $requests->count()) * 100, 1) : 0;
            $successRate = $data['total'] > 0 ? round(($data['success'] / $data['total']) * 100, 1) : 0;

            $this->info(sprintf(
                "%-15s | %-8d | %-8d | %-8d | %-10s",
                $typeNames[$type],
                $data['total'],
                $data['success'],
                $data['failed'],
                "{$percentage}% (TB: {$successRate}%)"
            ));
        }
        $this->info("═══════════════════════════════════════════════════════════════");
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

    /**
     * Sinh bước thời gian mô phỏng
     * Cố định 1 phút để đảm bảo độ chính xác tối đa và không bỏ sót sự kiện
     */
    private function getDynamicSimulationStep(): int
    {
        // Bước nhảy cố định 1 phút để:
        // - Đảm bảo xử lý check-in trong cửa sổ 15 phút
        // - Checkout chính xác đúng end_time
        // - Phát hiện expired kịp thời
        // - Dễ debug và theo dõi
        return 1;
    }
}

