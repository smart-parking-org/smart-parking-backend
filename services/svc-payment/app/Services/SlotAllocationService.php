<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\ParkingSlot;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\Log;

class SlotAllocationService
{
    /**
     * Thuật toán cấp chỗ - Phương án A: Hàng đợi ưu tiên + tìm chỗ gần nhất
     */
    public static function allocateSlotWithPriorityQueue(ReservationRequest $request): ?ParkingSlot
    {
        $startTime = microtime(true);

        try {
            // 1. Tính điểm ưu tiên
            $request->priority_score = $request->calculatePriorityScore();
            $request->save();

            // 2. Tính end_time từ start_time + duration
            $endTime = $request->desired_start_time->copy()->addMinutes($request->duration_minutes);

            // 3. Tìm slot khả dụng phù hợp với loại xe và thời gian
            $availableSlots = self::findAvailableSlotsInTimeRange(
                $request->parking_lot_id,
                $request->vehicle_type,
                $request->desired_start_time,
                $endTime
            );

            if ($availableSlots->isEmpty()) {
                Log::info("Không có slot khả dụng cho loại xe: {$request->vehicle_type}");
                return null;
            }

            // 3. Tìm slot gần nhất với cổng vào
            $parkingLot = $request->parkingLot;
            $nearestSlot = self::findNearestSlot(
                $availableSlots,
                $parkingLot->gate_pos_x,
                $parkingLot->gate_pos_y
            );

            // 4. Ghi log thời gian xử lý
            $processingTime = (microtime(true) - $startTime) * 1000;
            $request->update([
                'allocated_slot_id' => $nearestSlot->id,
                'processing_time_ms' => round($processingTime, 2),
                'processed_at' => now()
            ]);

            Log::info("Cấp chỗ thành công với hàng đợi ưu tiên", [
                'request_id' => $request->id,
                'slot_id' => $nearestSlot->id,
                'processing_time_ms' => $processingTime
            ]);

            return $nearestSlot;

        } catch (\Exception $e) {
            Log::error("Lỗi cấp chỗ hàng đợi ưu tiên", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Thuật toán cấp chỗ - Phương án B: Hungarian Algorithm (simplified)
     */
    public static function allocateSlotWithHungarian(ReservationRequest $request): ?ParkingSlot
    {
        $startTime = microtime(true);

        try {
            // 1. Lấy danh sách requests đang chờ
            $pendingRequests = ReservationRequest::where('parking_lot_id', $request->parking_lot_id)
                ->where('status', 'pending')
                ->orderBy('priority_score', 'desc')
                ->get();

            // 2. Tính end_time từ start_time + duration
            $endTime = $request->desired_start_time->copy()->addMinutes($request->duration_minutes);

            // 2. Lấy danh sách slots khả dụng
            $availableSlots = self::findAvailableSlotsInTimeRange(
                $request->parking_lot_id,
                $request->vehicle_type,
                $request->desired_start_time,
                $endTime
            );

            if ($availableSlots->isEmpty()) {
                Log::info("Không có slot khả dụng cho loại xe: {$request->vehicle_type}");
                return null;
            }

            // 3. Tạo ma trận cost (đơn giản hóa)
            $costMatrix = self::buildCostMatrix($pendingRequests, $availableSlots, $request->parkingLot);

            // 4. Tìm assignment tối ưu
            $assignment = self::hungarianAssignment($costMatrix);

            // 5. Cấp chỗ cho request hiện tại
            if (isset($assignment[0]) && $assignment[0] !== -1) {
                $slotIndex = $assignment[0];
                $allocatedSlot = $availableSlots[$slotIndex];

                $processingTime = (microtime(true) - $startTime) * 1000;
                $request->update([
                    'allocated_slot_id' => $allocatedSlot->id,
                    'processing_time_ms' => round($processingTime, 2),
                    'processed_at' => now()
                ]);

                Log::info("Cấp chỗ thành công với Hungarian", [
                    'request_id' => $request->id,
                    'slot_id' => $allocatedSlot->id,
                    'processing_time_ms' => $processingTime
                ]);

                return $allocatedSlot;
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Lỗi Hungarian algorithm", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Tìm slot khả dụng trong khoảng thời gian
     */
    public static function findAvailableSlotsInTimeRange($parkingLotId, $vehicleType, $startTime, $endTime)
    {
        $start = $startTime instanceof Carbon ? $startTime : Carbon::parse($startTime);
        $end = $endTime instanceof Carbon ? $endTime : Carbon::parse($endTime);

        // Lấy tất cả slot phù hợp với loại xe
        $slots = ParkingSlot::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->where('status', 'available')
            ->get();

        $availableSlots = collect();

        foreach ($slots as $slot) {
            if (!TimeOverlapService::hasOverlapOnSlot($slot->id, $start, $end)) {
                $availableSlots->push($slot);
            }
        }

        return $availableSlots;
    }

    /**
     * Tìm slot gần nhất với điểm cho trước
     */
    private static function findNearestSlot($slots, int $gateX, int $gateY): ParkingSlot
    {
        $minDistance = PHP_FLOAT_MAX;
        $nearestSlot = null;

        foreach ($slots as $slot) {
            $distance = sqrt(
                pow($slot->position_x - $gateX, 2) +
                pow($slot->position_y - $gateY, 2)
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestSlot = $slot;
            }
        }

        return $nearestSlot;
    }

    /**
     * Xây dựng ma trận cost cho Hungarian algorithm
     */
    private static function buildCostMatrix($requests, $slots, $parkingLot): array
    {
        $matrix = [];

        foreach ($requests as $i => $request) {
            foreach ($slots as $j => $slot) {
                // Cost = khoảng cách + penalty cho loại xe không phù hợp
                $distance = sqrt(
                    pow($slot->position_x - $parkingLot->gate_pos_x, 2) +
                    pow($slot->position_y - $parkingLot->gate_pos_y, 2)
                );

                $typePenalty = ($request->vehicle_type !== $slot->vehicle_type) ? 1000 : 0;

                $matrix[$i][$j] = $distance + $typePenalty;
            }
        }

        return $matrix;
    }

    /**
     * Simplified Hungarian assignment algorithm
     */
    private static function hungarianAssignment(array $costMatrix): array
    {
        $n = count($costMatrix);
        $m = count($costMatrix[0]);

        // Đơn giản hóa: chỉ tìm assignment cho request đầu tiên
        if ($n > 0 && $m > 0) {
            $minCost = min($costMatrix[0]);
            $minIndex = array_search($minCost, $costMatrix[0]);

            return [$minIndex]; // Chỉ trả về assignment cho request đầu tiên
        }

        return [-1];
    }
}
