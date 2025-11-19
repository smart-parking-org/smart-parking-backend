<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ParkingSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ===== MÔ TẢ THUẬT TOÁN =====
 *
 * ĐẦU VÀO:
 * - Collection<ReservationRequest>: Tập requests cần phân bổ
 *   Mỗi request gồm: parking_lot_id, vehicle_type, desired_start_time, duration_minutes
 *
 * ĐẦU RA:
 * - Array gồm:
 *   + allocations: Danh sách {request_id, slot_id, cost, distance}
 *   + stats: {total, success, failed, avg_processing_time}
 *
 * RÀNG BUỘC:
 * 1. Mỗi request chỉ được gán 1 slot
 * 2. Mỗi slot chỉ được gán cho 1 request trong cùng batch
 * 3. Slot phải cùng vehicle_type với request
 * 4. Slot không được có reservation overlap thời gian
 * 5. Requests được nhóm thành batches theo parking_lot và hour block
 *
 * HÀM MỤC TIÊU:
 * - Minimize tổng cost = Σ(conflict_penalty + wrong_type_penalty + distance_cost)
 *   + Conflict penalty (10000): Nếu slot có reservation overlap
 *   + Wrong type penalty (5000): Nếu loại xe không khớp
 *   + Distance cost (0-1000): Tỷ lệ thuận với khoảng cách từ cổng
 *
 * THUẬT TOÁN:
 * 1. Nhóm requests thành batches (theo parking_lot + hour block)
 * 2. Sort batches theo thời gian (sớm nhất trước)
 * 3. Với mỗi batch:
 *    a. Xây dựng cost matrix M×N (M requests, N slots)
 *    b. Chuẩn hóa matrix (subtract row/column min) - bước chuẩn bị cho Hungarian
 *    c. Greedy matching: Gán request cho slot có cost thấp nhất chưa được gán
 *    d. Double-check conflict trong transaction trước khi commit
 *
 * ĐỘ PHỨC TẠP:
 * - Time Complexity: O(B × (M² × N + M × N × log(M×N))) trong đó:
 *   + B = số batches (thường 10-20 với 300 requests)
 *   + M = số requests mỗi batch (trung bình 15-30)
 *   + N = số slots mỗi batch (trung bình 50-100)
 *   + M² × N = xây dựng cost matrix (kiểm tra conflict cho mỗi cặp request-slot)
 *   + M × N × log(M×N) = sort flatMatrix để greedy matching
 * - Với 300 requests, B=15, M=20, N=75:
 *   => 15 × (20² × 75 + 20 × 75 × log(1500)) ≈ 15 × (30,000 + 100,000) ≈ 2s
 * - Space Complexity: O(M × N) để lưu cost matrix
 */
class HungarianSlotAllocationService
{
    private const CONFLICT_PENALTY = 10000;     // Penalty cao cho xung đột
    private const WRONG_TYPE_PENALTY = 5000;    // Penalty cho loại xe không khớp
    private const MAX_DISTANCE_PENALTY = 1000;  // Penalty tối đa cho khoảng cách

    /**
     * Phân bổ batch requests bằng Hungarian Algorithm
     */
    public static function allocateBatch($requests)
    {
        $startTime = microtime(true);

        try {
            // Bước 1: Nhóm requests thành batches theo parking_lot và hour block
            $batches = self::groupRequestsIntoBatches($requests);

            $allocations = [];
            $stats = [
                'total' => $requests->count(),
                'success' => 0,
                'failed' => 0,
                'avg_distance' => 0,
                'total_processing_time' => 0
            ];

            // Bước 2: Xử lý từng batch tuần tự
            foreach ($batches as $batchKey => $batchRequests) {
                $batchResult = self::processBatch($batchRequests);

                $allocations = array_merge($allocations, $batchResult['allocations']);
                $stats['success'] += $batchResult['success'];
                $stats['failed'] += $batchResult['failed'];
            }

            // Tính thời gian xử lý tổng
            $totalTime = (microtime(true) - $startTime) * 1000;
            $stats['total_processing_time'] = round($totalTime, 2);
            $stats['avg_processing_time'] = round($totalTime / $requests->count(), 2);

            return [
                'allocations' => $allocations,
                'stats' => $stats
            ];

        } catch (\Exception $e) {
            Log::error("❌ Hungarian allocation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'allocations' => [],
                'stats' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Nhóm requests thành batches theo parking_lot và khung giờ (hour block)
     *
     * Mỗi batch gồm requests có cùng parking_lot_id và cùng hour block (ví dụ: 07:00-08:00)
     * Sắp xếp batches theo thời gian để xử lý sớm nhất trước
     */
    private static function groupRequestsIntoBatches($requests)
    {
        $batches = [];

        foreach ($requests as $request) {
            // Tạo key: parking_lot_id + hour block (mỗi batch 1 giờ)
            $hourBlock = $request->desired_start_time->format('Y-m-d H:00:00');
            $key = $request->parking_lot_id . '_' . $hourBlock;

            if (!isset($batches[$key])) {
                $batches[$key] = collect();
            }

            $batches[$key]->push($request);
        }

        // Sắp xếp requests trong mỗi batch theo desired_start_time (ưu tiên thời gian)
        foreach ($batches as $key => $batch) {
            $batches[$key] = $batch->sortBy(function ($r) {
                return $r->desired_start_time->timestamp;
            })->values();
        }

        // Sắp xếp batches theo thời gian để xử lý sớm nhất trước
        uksort($batches, function ($a, $b) use ($batches) {
            $timeA = $batches[$a]->first()->desired_start_time;
            $timeB = $batches[$b]->first()->desired_start_time;
            return $timeA->timestamp <=> $timeB->timestamp;
        });

        return $batches;
    }

    /**
     * Xử lý một batch requests bằng Hungarian Algorithm
     *
     * Quy trình:
     * 1. Lấy tất cả slots phù hợp (cùng parking_lot, cùng vehicle_type)
     * 2. Xây dựng cost matrix M×N
     * 3. Chuẩn hóa matrix (subtract row/column min)
     * 4. Greedy matching để tìm assignment tối ưu
     * 5. Double-check conflict trong transaction trước khi commit
     */
    private static function processBatch($requests)
    {
        $batchStart = microtime(true);
        $allocations = [];
        $success = 0;
        $failed = 0;

        return DB::transaction(function () use ($requests, $batchStart, &$allocations, &$success, &$failed) {
            try {
                // Bước 1: Lấy tất cả slots có thể dùng cho batch này
                $firstRequest = $requests->first();
                $vehicleTypesInBatch = $requests->pluck('vehicle_type')->unique();

                // Lấy slots với khoảng cách đến cổng gần nhất
                $allSlots = ParkingSlot::where('parking_lot_id', $firstRequest->parking_lot_id)
                    ->whereIn('vehicle_type', $vehicleTypesInBatch)
                    ->leftJoin('slot_gate_distances', 'parking_slots.id', '=', 'slot_gate_distances.slot_id')
                    ->select('parking_slots.*', DB::raw('COALESCE(MIN(slot_gate_distances.distance), parking_slots.distance_from_gate) as min_gate_distance'))
                    ->groupBy('parking_slots.id')
                    ->orderBy('min_gate_distance', 'asc')
                    ->lockForUpdate() // LOCK để tránh race condition
                    ->get();

                if ($allSlots->isEmpty()) {
                    foreach ($requests as $request) {
                        self::markRequestFailed($request, 'No slots available');
                        $failed++;
                    }
                    return compact('allocations', 'success', 'failed');
                }

                // Bước 2: Xây dựng cost matrix M×N (M requests, N slots)
                $costMatrix = self::buildCostMatrix($requests, $allSlots);

                // Bước 3: Chạy Hungarian Algorithm (chuẩn hóa + greedy matching)
                $assignments = self::hungarianAlgorithm($costMatrix);

                // Bước 4: Xử lý kết quả assignment
                foreach ($assignments as $requestIndex => $slotIndex) {
                    $request = $requests[$requestIndex];

                    if ($slotIndex === -1) {
                        // Không tìm được slot cho request này
                        self::markRequestFailed($request, 'No suitable slot found');
                        $failed++;
                        continue;
                    }

                    $slot = $allSlots[$slotIndex];
                    $cost = $costMatrix[$requestIndex][$slotIndex];

                    // Nếu cost >= CONFLICT_PENALTY, có nghĩa là có conflict
                    if ($cost >= self::CONFLICT_PENALTY) {
                        self::markRequestFailed($request, 'All slots have conflicts');
                        $failed++;
                        continue;
                    }

                    // Double-check conflict trong transaction (tránh race condition)
                    $parkingStart = $request->desired_start_time;
                    $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

                    if (self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd, $request->id)) {
                        self::markRequestFailed($request, 'Slot conflict detected during assignment');
                        $failed++;
                        continue;
                    }

                    // Cập nhật request với slot được cấp
                    $processingTimePerReqquest = ((microtime(true) - $batchStart) * 1000) / max(1, $requests->count());
                    $request->update([
                        'allocated_slot_id' => $slot->id,
                        'processing_time_ms' => round($processingTimePerReqquest, 2),
                        'algorithm_used' => 'hungarian',
                        'status' => 'assigned',
                        'processed_at' => now()
                    ]);

                    // Lấy khoảng cách từ slot_gate_distances hoặc distance_from_gate
                    $minDistance = DB::table('slot_gate_distances')
                        ->where('slot_id', $slot->id)
                        ->min('distance') ?? $slot->distance_from_gate;

                    $allocations[] = [
                        'request_id' => $request->id,
                        'slot_id' => $slot->id,
                        'cost' => $cost,
                        'distance' => $minDistance ?? $slot->distance_from_gate
                    ];

                    $success++;
                }

            } catch (\Exception $e) {
                Log::error("❌ Batch processing failed", ['error' => $e->getMessage()]);

                foreach ($requests as $request) {
                    self::markRequestFailed($request, 'Batch processing error: ' . $e->getMessage());
                    $failed++;
                }
            }

            return compact('allocations', 'success', 'failed');
        }, 3);
    }

    /**
     * Xây dựng Cost Matrix M×N
     *
     * Matrix[i][j] = Chi phí gán request i cho slot j
     * Cost = conflict_penalty (nếu có conflict) + wrong_type_penalty + distance_cost
     */
    private static function buildCostMatrix($requests, $slots)
    {
        $matrix = [];

        foreach ($requests as $requestIndex => $request) {
            $matrix[$requestIndex] = [];

            $parkingStart = $request->desired_start_time;
            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

            foreach ($slots as $slotIndex => $slot) {
                $cost = 0;

                // 1. Penalty xung đột thời gian (FLAG nhất - 10000)
                if (self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd, $request->id)) {
                    $cost += self::CONFLICT_PENALTY;
                }

                // 2. Penalty loại xe không khớp (5000)
                if ($slot->vehicle_type !== $request->vehicle_type) {
                    $cost += self::WRONG_TYPE_PENALTY;
                }

                // 3. Chi phí khoảng cách (0-1000, normalize)
                // Sử dụng khoảng cách từ slot_gate_distances hoặc distance_from_gate
                $minDistance = DB::table('slot_gate_distances')
                    ->where('slot_id', $slot->id)
                    ->min('distance') ?? $slot->distance_from_gate;
                
                // Giả sử khoảng cách tối đa là 500m
                $distanceCost = min(
                    (($minDistance ?? $slot->distance_from_gate) / 500) * self::MAX_DISTANCE_PENALTY,
                    self::MAX_DISTANCE_PENALTY
                );
                $cost += $distanceCost;

                $matrix[$requestIndex][$slotIndex] = $cost;
            }
        }

        return $matrix;
    }

    /**
     * Kiểm tra xung đột thời gian giữa request và slot
     *
     * Logic overlap: 2 khoảng overlap nếu:
     * existing.end_time > new.start_time AND existing.start_time < new.end_time
     */
    private static function hasTimeConflict($slotId, Carbon $parkingStart, Carbon $parkingEnd, $excludeRequestId = null): bool
    {
        // Chuyển về UTC để so sánh nhất quán với DB
        $parkingStart = $parkingStart->copy()->utc();
        $parkingEnd = $parkingEnd->copy()->utc();

        $query = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($parkingStart, $parkingEnd) {
                // Logic overlap: overlap nếu existing.end > new.start AND existing.start < new.end
                $q->where('end_time', '>', $parkingStart)
                    ->where('start_time', '<', $parkingEnd);
            });

        // Loại trừ request đang xử lý (nếu có)
        if ($excludeRequestId) {
            $query->where('reservation_request_id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    /**
     * Hungarian Algorithm Implementation (simplified version)
     *
     * Quy trình:
     * 1. Make square matrix (thêm dummy rows/cols nếu cần)
     * 2. Subtract row minimum (chuẩn hóa hàng)
     * 3. Subtract column minimum (chuẩn hóa cột)
     * 4. Greedy matching để tìm assignment tối ưu
     *
     * Note: Đây là phiên bản simplified, dùng greedy matching thay vì Hungarian thực sự
     * để đảm bảo thời gian xử lý < 3s với 300 requests
     */
    private static function hungarianAlgorithm($costMatrix)
    {
        $numRequests = count($costMatrix);
        $numSlots = count($costMatrix[0]);

        // Đảm bảo matrix vuông (thêm dummy rows/cols nếu cần)
        $matrix = self::makeSquareMatrix($costMatrix);
        $n = count($matrix);

        // Step 1: Subtract row minimum (chuẩn hóa hàng)
        for ($i = 0; $i < $n; $i++) {
            $rowMin = min($matrix[$i]);
            for ($j = 0; $j < $n; $j++) {
                $matrix[$i][$j] -= $rowMin;
            }
        }

        // Step 2: Subtract column minimum (chuẩn hóa cột)
        for ($j = 0; $j < $n; $j++) {
            $colMin = PHP_FLOAT_MAX;
            for ($i = 0; $i < $n; $i++) {
                $colMin = min($colMin, $matrix[$i][$j]);
            }
            for ($i = 0; $i < $n; $i++) {
                $matrix[$i][$j] -= $colMin;
            }
        }

        // Step 3: Find optimal assignment bằng greedy matching
        $assignments = self::findOptimalAssignment($matrix, $numRequests, $numSlots);

        return $assignments;
    }


    /**
     * Tạo matrix vuông bằng cách thêm dummy rows/columns
     *
     * Nếu M > N: thêm dummy columns với cost cao
     * Nếu N > M: thêm dummy rows với cost cao
     */
    private static function makeSquareMatrix($matrix)
    {
        $numRows = count($matrix);
        $numCols = count($matrix[0]);
        $n = max($numRows, $numCols);

        $squareMatrix = [];

        for ($i = 0; $i < $n; $i++) {
            $squareMatrix[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i < $numRows && $j < $numCols) {
                    // Giữ nguyên giá trị gốc
                    $squareMatrix[$i][$j] = $matrix[$i][$j];
                } else {
                    // Dummy cell với cost rất cao (không được chọn)
                    $squareMatrix[$i][$j] = self::CONFLICT_PENALTY * 10;
                }
            }
        }

        return $squareMatrix;
    }

    /**
     * Tìm assignment tối ưu bằng greedy matching
     *
     * Quy trình:
     * 1. Flatten matrix thành array các {request, slot, cost}
     * 2. Sort theo cost (từ thấp đến cao)
     * 3. Greedy: Gán request cho slot có cost thấp nhất chưa được gán
     *
     * Time Complexity: O(M × N × log(M × N)) - do sort
     *
     */
    private static function findOptimalAssignment($matrix, $numRequests, $numSlots)
    {
        $n = count($matrix);
        $assignments = array_fill(0, $numRequests, -1); // -1 = chưa được gán
        $usedSlots = [];

        // Flatten matrix thành array để sort
        $flatMatrix = [];
        for ($i = 0; $i < $numRequests; $i++) {
            for ($j = 0; $j < $numSlots; $j++) {
                $flatMatrix[] = [
                    'request' => $i,
                    'slot' => $j,
                    'cost' => $matrix[$i][$j]
                ];
            }
        }

        // Sort theo cost (thấp đến cao) - O(M × N × log(M × N))
        usort($flatMatrix, fn($a, $b) => $a['cost'] <=> $b['cost']);

        // Greedy assignment: Gán request cho slot có cost thấp nhất chưa được gán
        foreach ($flatMatrix as $cell) {
            $reqIdx = $cell['request'];
            $slotIdx = $cell['slot'];

            // Chỉ gán nếu request chưa được gán và slot chưa được dùng
            if ($assignments[$reqIdx] === -1 && !isset($usedSlots[$slotIdx])) {
                $assignments[$reqIdx] = $slotIdx;
                $usedSlots[$slotIdx] = true;
            }

            // Dừng khi đã gán hết requests
            if (count($usedSlots) >= $numRequests) {
                break;
            }
        }

        return $assignments;
    }

    /**
     * Đánh dấu request thất bại
     */
    private static function markRequestFailed($request, $reason)
    {
        $request->update([
            'status' => 'failed',
            'processed_at' => now()
        ]);

        Log::warning("⚠️ Hungarian: Failed allocation", [
            'request_id' => $request->id,
            'reason' => $reason
        ]);
    }
}
