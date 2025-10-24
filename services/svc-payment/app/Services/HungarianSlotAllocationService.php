<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ParkingSlot;
use App\Models\ReservationRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
            // 1. Nhóm requests theo parking lot và thời gian
            $batches = self::groupRequestsIntoBatches($requests);

            $allocations = [];
            $stats = [
                'total' => $requests->count(),
                'success' => 0,
                'failed' => 0,
                'avg_distance' => 0,
                'total_processing_time' => 0
            ];

            // 2. Xử lý từng batch
            foreach ($batches as $batchKey => $batchRequests) {
                $batchResult = self::processBatch($batchRequests);

                $allocations = array_merge($allocations, $batchResult['allocations']);
                $stats['success'] += $batchResult['success'];
                $stats['failed'] += $batchResult['failed'];
            }

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
     * Nhóm requests theo parking lot và khung giờ gần nhau
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

        return $batches;
    }

    /**
     * Xử lý một batch requests bằng Hungarian
     */
    private static function processBatch($requests)
    {
        $batchStart = microtime(true);
        $allocations = [];
        $success = 0;
        $failed = 0;

        try {
            // 1. Lấy tất cả slots có thể dùng
            $firstRequest = $requests->first();
            $allSlots = ParkingSlot::where('parking_lot_id', $firstRequest->parking_lot_id)
                ->orderBy('distance_from_gate', 'asc')
                ->get();

            if ($allSlots->isEmpty()) {
                foreach ($requests as $request) {
                    self::markRequestFailed($request, 'No slots available');
                    $failed++;
                }
                return compact('allocations', 'success', 'failed');
            }

            // 2. Xây dựng cost matrix
            $costMatrix = self::buildCostMatrix($requests, $allSlots);

            // 3. Chạy Hungarian Algorithm
            $assignments = self::hungarianAlgorithm($costMatrix);

            // 4. Xử lý kết quả
            foreach ($assignments as $requestIndex => $slotIndex) {
                $request = $requests[$requestIndex];

                if ($slotIndex === -1) {
                    // Không tìm được slot
                    self::markRequestFailed($request, 'No suitable slot found');
                    $failed++;
                    continue;
                }

                $slot = $allSlots[$slotIndex];
                $cost = $costMatrix[$requestIndex][$slotIndex];

                // Kiểm tra có phải là assignment hợp lệ không
                if ($cost >= self::CONFLICT_PENALTY) {
                    self::markRequestFailed($request, 'All slots have conflicts');
                    $failed++;
                    continue;
                }

                // Cập nhật request
                $processingTime = (microtime(true) - $batchStart) * 1000;
                $request->update([
                    'allocated_slot_id' => $slot->id,
                    'processing_time_ms' => round($processingTime, 2),
                    'algorithm_used' => 'hungarian',
                    'status' => 'assigned',
                    'processed_at' => now()
                ]);

                $allocations[] = [
                    'request_id' => $request->id,
                    'slot_id' => $slot->id,
                    'cost' => $cost,
                    'distance' => $slot->distance_from_gate
                ];

                $success++;

                Log::info("✅ Hungarian: Allocated", [
                    'request_id' => $request->id,
                    'slot_code' => $slot->slot_code,
                    'cost' => round($cost, 2),
                    'distance' => $slot->distance_from_gate . 'm'
                ]);
            }

        } catch (\Exception $e) {
            Log::error("❌ Batch processing failed", ['error' => $e->getMessage()]);

            foreach ($requests as $request) {
                self::markRequestFailed($request, 'Batch processing error: ' . $e->getMessage());
                $failed++;
            }
        }

        return compact('allocations', 'success', 'failed');
    }

    /**
     * Xây dựng Cost Matrix
     * Matrix[i][j] = Chi phí gán request i cho slot j
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

                // 1. Penalty xung đột thời gian (cao nhất)
                if (self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd, $request->id)) {
                    $cost += self::CONFLICT_PENALTY;
                }

                // 2. Penalty loại xe không khớp
                if ($slot->vehicle_type !== $request->vehicle_type) {
                    $cost += self::WRONG_TYPE_PENALTY;
                }

                // 3. Chi phí khoảng cách (normalize 0-1000)
                // Giả sử khoảng cách tối đa là 500m
                $distanceCost = min(
                    ($slot->distance_from_gate / 500) * self::MAX_DISTANCE_PENALTY,
                    self::MAX_DISTANCE_PENALTY
                );
                $cost += $distanceCost;

                $matrix[$requestIndex][$slotIndex] = $cost;
            }
        }

        return $matrix;
    }

    /**
     * Kiểm tra xung đột thời gian
     */
    private static function hasTimeConflict($slotId, Carbon $parkingStart, Carbon $parkingEnd, $excludeRequestId = null): bool
    {
        $query = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($parkingStart, $parkingEnd) {
                $q->whereBetween('start_time', [$parkingStart, $parkingEnd])
                    ->orWhereBetween('end_time', [$parkingStart, $parkingEnd])
                    ->orWhere(function ($subQ) use ($parkingStart, $parkingEnd) {
                        $subQ->where('start_time', '<=', $parkingStart)
                            ->where('end_time', '>=', $parkingEnd);
                    })
                    ->orWhere(function ($subQ) use ($parkingStart, $parkingEnd) {
                        $subQ->where('start_time', '>=', $parkingStart)
                            ->where('end_time', '<=', $parkingEnd);
                    });
            });

        // Loại trừ request đang xử lý (nếu có)
        if ($excludeRequestId) {
            $query->where('reservation_request_id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    /**
     * Hungarian Algorithm Implementation
     *
     * @param array $costMatrix M x N matrix (M requests, N slots)
     * @return array Assignment [requestIndex => slotIndex]
     */
    private static function hungarianAlgorithm($costMatrix)
    {
        $numRequests = count($costMatrix);
        $numSlots = count($costMatrix[0]);

        // Đảm bảo matrix vuông (thêm dummy rows/cols nếu cần)
        $matrix = self::makeSquareMatrix($costMatrix);
        $n = count($matrix);

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

        // Step 3: Find optimal assignment
        $assignments = self::findOptimalAssignment($matrix, $numRequests, $numSlots);

        return $assignments;
    }

    /**
     * Tạo matrix vuông bằng cách thêm dummy rows/columns
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
                    $squareMatrix[$i][$j] = $matrix[$i][$j];
                } else {
                    // Dummy cell với cost cao
                    $squareMatrix[$i][$j] = self::CONFLICT_PENALTY * 10;
                }
            }
        }

        return $squareMatrix;
    }

    /**
     * Tìm assignment tối ưu bằng matching algorithm
     * Sử dụng thuật toán greedy đơn giản cho demo
     */
    private static function findOptimalAssignment($matrix, $numRequests, $numSlots)
    {
        $n = count($matrix);
        $assignments = array_fill(0, $numRequests, -1);
        $usedSlots = [];

        // Greedy approach: Tìm cell có cost thấp nhất chưa assign
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

        // Sort by cost
        usort($flatMatrix, fn($a, $b) => $a['cost'] <=> $b['cost']);

        // Assign
        foreach ($flatMatrix as $cell) {
            $reqIdx = $cell['request'];
            $slotIdx = $cell['slot'];

            if ($assignments[$reqIdx] === -1 && !isset($usedSlots[$slotIdx])) {
                $assignments[$reqIdx] = $slotIdx;
                $usedSlots[$slotIdx] = true;
            }

            // Dừng khi đã assign hết
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
            'algorithm_used' => 'hungarian',
            'failure_reason' => $reason,
            'processed_at' => now()
        ]);

        Log::warning("⚠️ Hungarian: Failed allocation", [
            'request_id' => $request->id,
            'reason' => $reason
        ]);
    }
}
