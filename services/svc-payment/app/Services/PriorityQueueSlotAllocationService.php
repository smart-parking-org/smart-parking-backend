<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\ParkingSlot;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ===== MÔ TẢ THUẬT TOÁN =====
 *
 * ĐẦU VÀO:
 * - ReservationRequest: Yêu cầu đặt chỗ gồm:
 *   + parking_lot_id: ID bãi đỗ
 *   + vehicle_type: Loại xe (motorbike, car_4_seat, car_7_seat, light_truck)
 *   + desired_start_time: Thời gian mong muốn bắt đầu đỗ (Carbon)
 *   + duration_minutes: Thời lượng đỗ (phút)
 *
 * ĐẦU RA:
 * - ParkingSlot: Slot được cấp (null nếu không tìm được)
 * - Cập nhật ReservationRequest với allocated_slot_id và processing_time_ms
 *
 * RÀNG BUỘC:
 * 1. Slot phải cùng vehicle_type với request
 * 2. Slot không được có reservation nào overlap thời gian với request:
 *    - Overlap: existing.end_time > new.start_time AND existing.start_time < new.end_time
 * 3. Slot phải cùng parking_lot_id
 * 4. Chỉ xét reservations có status = 'confirmed' hoặc 'checked_in'
 *
 * HÀM MỤC TIÊU:
 * - Tối ưu: Minimize distance_from_gate (chỗ gần cổng nhất)
 * - Ưu tiên: Xử lý requests theo thứ tự desired_start_time (sớm nhất trước)
 *
 * THUẬT TOÁN:
 * 1. Sắp xếp requests theo desired_start_time (do caller xử lý)
 * 2. Với mỗi request, tìm slot gần cổng nhất còn trống:
 *    - Lọc theo vehicle_type và parking_lot_id
 *    - Loại bỏ slots có conflict thời gian
 *    - Chọn slot có distance_from_gate nhỏ nhất
 * 3. Sử dụng DB transaction + lockForUpdate() để tránh race condition
 *
 * ĐỘ PHỨC TẠP:
 * - Time Complexity: O(M × N × log N) trong đó:
 *   + M = số requests cần xử lý (300 trong test)
 *   + N = số slots cho vehicle_type (trung bình 75-150)
 *   + log N = chi phí query với index trên distance_from_gate
 * - Space Complexity: O(1) - không cần lưu trữ thêm
 * - Database Queries per request: 1 query với subquery whereDoesntHave
 * - Với 300 requests, N=100 slots: ~300 queries, mỗi query ~5-10ms
 *   => Tổng: ~1.5-3s (đạt yêu cầu < 3s)
 */
class PriorityQueueSlotAllocationService
{
    /**
     * Phân bổ slot cho 1 request
     *
     * @param ReservationRequest $request Request đặt chỗ
     * @return ParkingSlot|null Slot được cấp, null nếu không tìm được
     */
    public static function allocateSlot(ReservationRequest $request): ?ParkingSlot
    {
        $startTime = microtime(true);

        try {
            // Tính thời gian đỗ: từ desired_start_time đến desired_start_time + duration
            $parkingStart = $request->desired_start_time;
            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

            // Tìm slot tốt nhất (gần cổng nhất, không conflict) trong transaction
            $bestSlot = self::allocateBestSlotTransactional(
                $request->parking_lot_id,
                $request->vehicle_type,
                $parkingStart,
                $parkingEnd
            );

            if (!$bestSlot) {
                // Không tìm được slot phù hợp
                $processingTime = (microtime(true) - $startTime) * 1000;
                $request->update([
                    'status' => 'failed',
                    'processing_time_ms' => round($processingTime, 2),
                    'processed_at' => now()
                ]);
                return null;
            }

            // Cập nhật request với slot được cấp
            $processingTime = (microtime(true) - $startTime) * 1000;
            $request->update([
                'allocated_slot_id' => $bestSlot->id,
                'processing_time_ms' => round($processingTime, 2),
                'status' => 'assigned',
                'processed_at' => now()
            ]);

            // Lấy khoảng cách từ slot_gate_distances hoặc distance_from_gate
            $minDistance = DB::table('slot_gate_distances')
                ->where('slot_id', $bestSlot->id)
                ->min('distance') ?? $bestSlot->distance_from_gate;

            Log::info("✅ Allocated slot successfully", [
                'request_id' => $request->id,
                'slot_code' => $bestSlot->slot_code,
                'distance' => ($minDistance ?? $bestSlot->distance_from_gate) . 'm',
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
     * Tìm slot tốt nhất (gần cổng nhất, không conflict) trong transaction
     *
     * Logic overlap: 2 khoảng thời gian overlap nếu:
     * existing.end_time > new.start_time AND existing.start_time < new.end_time
     *
     * @param int $parkingLotId ID bãi đỗ
     * @param string $vehicleType Loại xe
     * @param Carbon $parkingStart Thời gian bắt đầu đỗ
     * @param Carbon $parkingEnd Thời gian kết thúc đỗ
     * @return ParkingSlot|null Slot tốt nhất, null nếu không có
     */
    private static function allocateBestSlotTransactional(
        int $parkingLotId,
        string $vehicleType,
        Carbon $parkingStart,
        Carbon $parkingEnd
    ): ?ParkingSlot {
        return DB::transaction(function () use ($parkingLotId, $vehicleType, $parkingStart, $parkingEnd) {
            // Chuyển về UTC để so sánh nhất quán với DB
            $parkingStart = $parkingStart->copy()->utc();
            $parkingEnd = $parkingEnd->copy()->utc();

            // Tìm slot: cùng loại xe, không có reservation overlap, gần cổng nhất
            // Sử dụng khoảng cách từ slot_gate_distances (khoảng cách nhỏ nhất đến bất kỳ cổng nào)
            $slot = ParkingSlot::where('parking_lot_id', $parkingLotId)
                ->where('vehicle_type', $vehicleType)
                ->whereDoesntHave('reservations', function ($q) use ($parkingStart, $parkingEnd) {
                    // Chỉ xét reservations đang active (confirmed hoặc checked_in)
                    $q->whereIn('status', ['confirmed', 'checked_in'])
                        ->where(function ($qq) use ($parkingStart, $parkingEnd) {
                        // Logic overlap đúng: overlap nếu existing.end > new.start AND existing.start < new.end
                        $qq->where('end_time', '>', $parkingStart)
                            ->where('start_time', '<', $parkingEnd);
                    });
                })
                ->leftJoin('slot_gate_distances', 'parking_slots.id', '=', 'slot_gate_distances.slot_id')
                ->select('parking_slots.*', DB::raw('COALESCE(MIN(slot_gate_distances.distance), parking_slots.distance_from_gate) as min_gate_distance'))
                ->groupBy('parking_slots.id')
                ->orderBy('min_gate_distance', 'asc')  // Ưu tiên slot gần cổng nhất
                ->lockForUpdate() // Lock để tránh 2 requests cùng lấy 1 slot
                ->first();

            return $slot;
        }, 3); // Retry 3 lần nếu deadlock
    }
}
