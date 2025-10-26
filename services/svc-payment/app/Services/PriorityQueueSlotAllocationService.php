<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\Carbon;
use App\Models\ParkingSlot;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\Log;

class PriorityQueueSlotAllocationService
{
    public static function allocateSlot(ReservationRequest $request): ?ParkingSlot
    {
        $startTime = microtime(true);

        try {
            // Lấy thời gian đỗ xe từ request
            $parkingStart = $request->desired_start_time;
            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

            // Tìm chỗ trống
            $availableSlots = self::findAvailableSlots(
                $request->parking_lot_id,
                $request->vehicle_type,
                $parkingStart,
                $parkingEnd
            );

            if ($availableSlots->isEmpty()) {
                $processingTime = (microtime(true) - $startTime) * 1000;
                $request->update([
                    'status' => 'failed',
                    'processing_time_ms' => round($processingTime, 2),
                    'processed_at' => now()
                ]);
                return null;
            }

            // Chọn chỗ gần cổng nhất
            $bestSlot = $availableSlots->first();

            // Cập nhật request
            $processingTime = (microtime(true) - $startTime) * 1000;
            $request->update([
                'allocated_slot_id' => $bestSlot->id,
                'processing_time_ms' => round($processingTime, 2),
                'status' => 'assigned',
                'processed_at' => now()
            ]);

            Log::info("✅ Allocated slot successfully", [
                'request_id' => $request->id,
                'slot_code' => $bestSlot->slot_code,
                'distance' => $bestSlot->distance_from_gate . 'm',
                'time_range' => $parkingStart->format('Y-m-d H:i') . ' - ' . $parkingEnd->format('H:i'),
                'processing_ms' => round($processingTime, 2)
            ]);

            return $bestSlot;

        } catch (\Exception $e) {
            Log::error("❌ Slot allocation failed", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);

            $request->update([
                'status' => 'failed',
                'processed_at' => now()
            ]);

            return null;
        }
    }

    /**
     * Tìm chỗ trống dựa trên THỜI GIAN đỗ xe
     */
    private static function findAvailableSlots(
        int $parkingLotId,
        string $vehicleType,
        Carbon $parkingStart,
        Carbon $parkingEnd
    ) {
        // Lấy tất cả chỗ phù hợp, sắp xếp theo khoảng cách từ cổng
        $slots = ParkingSlot::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->orderBy('distance_from_gate', 'asc')
            ->get();

        $availableSlots = collect();

        foreach ($slots as $slot) {
            if (!self::hasTimeConflict($slot->id, $parkingStart, $parkingEnd)) {
                $availableSlots->push($slot);
            }
        }

        return $availableSlots;
    }

    /**
     * Kiểm tra xung đột dựa trên start_time và end_time
     */
    private static function hasTimeConflict($slotId, Carbon $parkingStart, Carbon $parkingEnd): bool
    {
        $conflict = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($query) use ($parkingStart, $parkingEnd) {
                $query
                    // Case 1: Reservation mới bắt đầu trong khoảng đã đặt
                    ->whereBetween('start_time', [$parkingStart, $parkingEnd])
                    // Case 2: Reservation mới kết thúc trong khoảng đã đặt
                    ->orWhereBetween('end_time', [$parkingStart, $parkingEnd])
                    // Case 3: Reservation mới bao trùm reservation cũ
                    ->orWhere(function ($q) use ($parkingStart, $parkingEnd) {
                        $q->where('start_time', '<=', $parkingStart)
                            ->where('end_time', '>=', $parkingEnd);
                    })
                    // Case 4: Reservation cũ bao trùm reservation mới
                    ->orWhere(function ($q) use ($parkingStart, $parkingEnd) {
                        $q->where('start_time', '>=', $parkingStart)
                            ->where('end_time', '<=', $parkingEnd);
                    });
            })
            ->exists();

        return $conflict;
    }
}
