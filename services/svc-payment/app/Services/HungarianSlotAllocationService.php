<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ParkingSlot;
use App\Models\ReservationRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hungarian Algorithm (Kuhn-Munkres) for Batch Slot Allocation
 *
 * Triển khai đầy đủ thuật toán Hungarian để tìm minimum cost assignment
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
            // Nhóm requests thành batches theo parking_lot và hour block
            $batches = self::groupRequestsIntoBatches($requests);

            $allocations = [];
            $stats = [
                'total' => $requests->count(),
                'success' => 0,
                'failed' => 0,
                'avg_distance' => 0,
                'total_processing_time' => 0
            ];

            // Xử lý từng batch tuần tự
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
     * Nhóm requests thành batches theo parking_lot và khung giờ
     */
    private static function groupRequestsIntoBatches($requests)
    {
        $batches = [];

        foreach ($requests as $request) {
            $hourBlock = $request->desired_start_time->format('Y-m-d H:00:00');
            $key = $request->parking_lot_id . '_' . $hourBlock;

            if (!isset($batches[$key])) {
                $batches[$key] = collect();
            }

            $batches[$key]->push($request);
        }

        // Sắp xếp requests trong mỗi batch theo thời gian
        foreach ($batches as $key => $batch) {
            $batches[$key] = $batch->sortBy(function ($r) {
                return $r->desired_start_time->timestamp;
            })->values();
        }

        // Sắp xếp batches theo thời gian
        uksort($batches, function ($a, $b) use ($batches) {
            $timeA = $batches[$a]->first()->desired_start_time;
            $timeB = $batches[$b]->first()->desired_start_time;
            return $timeA->timestamp <=> $timeB->timestamp;
        });

        return $batches;
    }

    /**
     * Xử lý một batch requests bằng Hungarian Algorithm
     */
    private static function processBatch($requests)
    {
        $batchStart = microtime(true);
        $allocations = [];
        $success = 0;
        $failed = 0;

        return DB::transaction(function () use ($requests, $batchStart, &$allocations, &$success, &$failed) {
            try {
                $firstRequest = $requests->first();
                $vehicleTypesInBatch = $requests->pluck('vehicle_type')->unique();

                // Lấy tất cả slots cho tất cả vehicle types trong batch
                $allSlots = collect();
                foreach ($vehicleTypesInBatch as $vehicleType) {
                    $slotsForType = ParkingSlot::where('parking_lot_id', $firstRequest->parking_lot_id)
                        ->where('vehicle_type', $vehicleType)
                        ->lockForUpdate()
                        ->get();

                    $allSlots = $allSlots->merge($slotsForType);
                }

                if ($allSlots->isEmpty()) {
                    foreach ($requests as $request) {
                        self::markRequestFailed($request, 'No slots available');
                        $failed++;
                    }
                    return compact('allocations', 'success', 'failed');
                }

                // Xây dựng cost matrix
                $costMatrix = self::buildCostMatrix($requests, $allSlots);
                $originalCostMatrix = $costMatrix; // Lưu cost gốc để kiểm tra sau

                // Chạy Hungarian Algorithm (Kuhn-Munkres)
                $assignments = self::hungarianAlgorithm($costMatrix, $originalCostMatrix, count($requests), count($allSlots));

                // Xử lý kết quả assignment
                foreach ($assignments as $requestIndex => $slotIndex) {
                    $request = $requests[$requestIndex];

                    if ($slotIndex === -1) {
                        self::markRequestFailed($request, 'No suitable slot found');
                        $failed++;
                        continue;
                    }

                    $slot = $allSlots[$slotIndex];
                    $originalCost = $originalCostMatrix[$requestIndex][$slotIndex];

                    // Kiểm tra lại: cost phải < WRONG_TYPE_PENALTY (tức là đúng type và không có conflict)
                    if ($originalCost >= self::WRONG_TYPE_PENALTY) {
                        self::markRequestFailed($request, 'No suitable slot found (wrong type or conflict)');
                        $failed++;
                        continue;
                    }

                    // Double-check conflict với DB (tránh race condition)
                    $parkingStart = $request->desired_start_time->copy()->utc();
                    $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

                    if (self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd, $request->id)) {
                        Log::warning("⚠️ Hungarian: Conflict detected during assignment", [
                            'request_id' => $request->id,
                            'slot_id' => $slot->id
                        ]);
                        self::markRequestFailed($request, 'Slot conflict detected during assignment');
                        $failed++;
                        continue;
                    }

                    // Cập nhật request
                    $processingTimePerRequest = ((microtime(true) - $batchStart) * 1000) / max(1, $requests->count());
                    $request->update([
                        'allocated_slot_id' => $slot->id,
                        'processing_time_ms' => round($processingTimePerRequest, 2),
                        'algorithm_used' => 'hungarian',
                        'status' => 'assigned',
                        'processed_at' => now()
                    ]);

                    // Lấy khoảng cách
                    $distance = DB::table('slot_gate_distances')
                        ->where('slot_id', $slot->id)
                        ->min('distance');

                    // Nếu không có dữ liệu distance, sử dụng giá trị mặc định
                    if ($distance === null) {
                        $distance = 500; // Khoảng cách trung bình
                    }

                    $allocations[] = [
                        'request_id' => $request->id,
                        'slot_id' => $slot->id,
                        'cost' => $originalCost,
                        'distance' => $distance
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

            $parkingStart = $request->desired_start_time->copy()->utc();
            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

            foreach ($slots as $slotIndex => $slot) {
                $cost = 0;

                // 1. Kiểm tra vehicle_type
                $isWrongType = ($slot->vehicle_type !== $request->vehicle_type);
                if ($isWrongType) {
                    $cost += self::WRONG_TYPE_PENALTY;
                    $matrix[$requestIndex][$slotIndex] = $cost;
                    continue; // Không cần kiểm tra conflict nếu sai type
                }

                // 2. Kiểm tra conflict (chỉ nếu đúng type)
                $hasConflict = self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd, $request->id);
                if ($hasConflict) {
                    $cost += self::CONFLICT_PENALTY;
                }

                // 3. Chi phí khoảng cách
                if ($request->gate_id) {
                    $distance = DB::table('slot_gate_distances')
                        ->where('slot_id', $slot->id)
                        ->where('gate_id', $request->gate_id)
                        ->value('distance');
                } else {
                    $distance = DB::table('slot_gate_distances')
                        ->where('slot_id', $slot->id)
                        ->min('distance');
                }

                // Nếu không có dữ liệu distance, sử dụng giá trị mặc định (500m - khoảng cách trung bình)
                if ($distance === null) {
                    $distance = 500;
                }

                $distanceCost = min(
                    ($distance / 500) * self::MAX_DISTANCE_PENALTY,
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
     * Sử dụng logic giống hệt Priority Queue để đảm bảo nhất quán
     */
    private static function hasTimeConflict($slotId, Carbon $parkingStart, Carbon $parkingEnd, $excludeRequestId = null): bool
    {
        // Đảm bảo đã chuyển về UTC
        $parkingStart = $parkingStart->copy()->utc();
        $parkingEnd = $parkingEnd->copy()->utc();

        // Logic giống Priority Queue: Kiểm tra với reservations đang active
        $query = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($parkingStart, $parkingEnd) {
                // Logic overlap: existing.end > new.start AND existing.start < new.end
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
     * Hungarian Algorithm (Kuhn-Munkres) Implementation
     *
     * Tìm minimum cost assignment trong bipartite graph
     *
     * @param array $costMatrix Cost matrix M×N (M requests, N slots)
     * @param array $originalCostMatrix Cost matrix gốc để kiểm tra conflict/wrong type
     * @param int $numRequests Số lượng requests
     * @param int $numSlots Số lượng slots
     * @return array Assignments: [requestIndex => slotIndex, ...] (-1 nếu không được gán)
     */
    private static function hungarianAlgorithm($costMatrix, $originalCostMatrix, $numRequests, $numSlots)
    {
        // Đảm bảo matrix vuông (thêm dummy rows/cols nếu cần)
        $n = max($numRequests, $numSlots);
        $matrix = self::makeSquareMatrix($costMatrix, $numRequests, $numSlots, $n);

        // Step 1: Subtract row minimum
        for ($i = 0; $i < $n; $i++) {
            $rowMin = min($matrix[$i]);
            for ($j = 0; $j < $n; $j++) {
                $matrix[$i][$j] -= $rowMin;
            }
        }

        // Step 2: Subtract column minimum
        for ($j = 0; $j < $n; $j++) {
            $colMin = PHP_FLOAT_MAX;
            for ($i = 0; $i < $n; $i++) {
                $colMin = min($colMin, $matrix[$i][$j]);
            }
            for ($i = 0; $i < $n; $i++) {
                $matrix[$i][$j] -= $colMin;
            }
        }

        // Step 3: Find maximum matching using augmenting path
        $assignments = array_fill(0, $numRequests, -1);
        $usedSlots = [];

        // Khởi tạo: Tìm các zeros và gán trực tiếp nếu có thể
        for ($i = 0; $i < $numRequests; $i++) {
            for ($j = 0; $j < $numSlots; $j++) {
                // Chỉ gán nếu:
                // 1. Cost sau chuẩn hóa = 0 (hoặc gần 0)
                // 2. Original cost < WRONG_TYPE_PENALTY (đúng type và không conflict)
                // 3. Request và slot chưa được gán
                if (
                    $matrix[$i][$j] < 0.001
                    && $originalCostMatrix[$i][$j] < self::WRONG_TYPE_PENALTY
                    && $assignments[$i] === -1
                    && !isset($usedSlots[$j])
                ) {
                    $assignments[$i] = $j;
                    $usedSlots[$j] = true;
                }
            }
        }

        // Step 4: Tìm augmenting path cho các requests chưa được gán
        for ($i = 0; $i < $numRequests; $i++) {
            if ($assignments[$i] === -1) {
                self::findAugmentingPath($matrix, $originalCostMatrix, $assignments, $usedSlots, $i, $numRequests, $numSlots);
            }
        }

        return $assignments;
    }

    /**
     * Tạo matrix vuông bằng cách thêm dummy rows/columns
     */
    private static function makeSquareMatrix($matrix, $numRequests, $numSlots, $n)
    {
        $squareMatrix = [];

        for ($i = 0; $i < $n; $i++) {
            $squareMatrix[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i < $numRequests && $j < $numSlots) {
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
     * Tìm augmenting path để gán request chưa được gán
     *
     * Sử dụng DFS để tìm path từ request chưa được gán đến slot chưa được dùng
     */
    private static function findAugmentingPath($matrix, $originalCostMatrix, &$assignments, &$usedSlots, $requestIndex, $numRequests, $numSlots)
    {
        $visited = [];
        $path = [];

        if (self::dfs($matrix, $originalCostMatrix, $assignments, $usedSlots, $visited, $path, $requestIndex, $numRequests, $numSlots)) {
            // Đảo ngược path để gán
            for ($i = 0; $i < count($path) - 1; $i += 2) {
                $reqIdx = $path[$i];
                $slotIdx = $path[$i + 1];
                $assignments[$reqIdx] = $slotIdx;
                $usedSlots[$slotIdx] = true;
            }
        }
    }

    /**
     * DFS để tìm augmenting path
     */
    private static function dfs($matrix, $originalCostMatrix, $assignments, &$usedSlots, &$visited, &$path, $requestIndex, $numRequests, $numSlots)
    {
        if (isset($visited[$requestIndex])) {
            return false;
        }

        $visited[$requestIndex] = true;
        $path[] = $requestIndex;

        // Tìm slot chưa được dùng có cost thấp (sau chuẩn hóa gần 0)
        for ($j = 0; $j < $numSlots; $j++) {
            // Chỉ xét nếu original cost < WRONG_TYPE_PENALTY (đúng type và không conflict)
            if ($originalCostMatrix[$requestIndex][$j] >= self::WRONG_TYPE_PENALTY) {
                continue;
            }

            // Nếu slot chưa được dùng và cost sau chuẩn hóa gần 0
            if (!isset($usedSlots[$j]) && $matrix[$requestIndex][$j] < 0.001) {
                $path[] = $j;
                return true; // Tìm thấy augmenting path
            }

            // Nếu slot đã được dùng, tìm request khác đã gán slot này
            if (isset($usedSlots[$j])) {
                $assignedRequest = array_search($j, $assignments);
                if ($assignedRequest !== false && $assignedRequest !== $requestIndex) {
                    // Thử tìm path từ request đã được gán
                    if (self::dfs($matrix, $originalCostMatrix, $assignments, $usedSlots, $visited, $path, $assignedRequest, $numRequests, $numSlots)) {
                        $path[] = $j;
                        return true;
                    }
                }
            }
        }

        array_pop($path); // Backtrack
        return false;
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
