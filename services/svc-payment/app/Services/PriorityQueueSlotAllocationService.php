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
 * - Tối ưu: Minimize distance từ slot_gate_distances (chỗ gần cổng nhất)
 * - Ưu tiên: Xử lý requests theo thứ tự desired_start_time (sớm nhất trước)
 *
 * THUẬT TOÁN:
 * 1. Sắp xếp requests theo desired_start_time (do caller xử lý)
 * 2. Với mỗi request, tìm slot gần cổng nhất còn trống:
 *    - Lọc theo vehicle_type và parking_lot_id
 *    - Loại bỏ slots có conflict thời gian
 *    - Chọn slot có distance nhỏ nhất từ bảng slot_gate_distances
 * 3. Sử dụng DB transaction + lockForUpdate() để tránh race condition
 *
 * ĐỘ PHỨC TẠP:
 * - Time Complexity: O(M × N × log N) trong đó:
 *   + M = số requests cần xử lý (300 trong test)
 *   + N = số slots cho vehicle_type (trung bình 75-150)
 *   + log N = chi phí query với index trên slot_gate_distances.distance
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
            // Đảm bảo chuyển về UTC để nhất quán với DB
            $parkingStart = $request->desired_start_time->copy()->utc();
            $parkingEnd = $parkingStart->copy()->addMinutes($request->duration_minutes);

            // Tìm slot tốt nhất (gần cổng được chọn nhất, không conflict) trong transaction
            $bestSlot = self::allocateBestSlotTransactional(
                $request->parking_lot_id,
                $request->vehicle_type,
                $parkingStart,
                $parkingEnd,
                $request->gate_id
            );

            if (!$bestSlot) {
                // Debug: Kiểm tra tại sao không tìm được slot
                $totalSlots = ParkingSlot::where('parking_lot_id', $request->parking_lot_id)
                    ->where('vehicle_type', $request->vehicle_type)
                    ->count();

                // Debug: Kiểm tra chi tiết
                $availableSlots = ParkingSlot::where('parking_lot_id', $request->parking_lot_id)
                    ->where('vehicle_type', $request->vehicle_type)
                    ->whereDoesntHave('reservations', function ($q) use ($parkingStart, $parkingEnd) {
                        $q->whereIn('status', ['confirmed', 'checked_in'])
                            ->where(function ($qq) use ($parkingStart, $parkingEnd) {
                                $qq->where('end_time', '>', $parkingStart)
                                    ->where('start_time', '<', $parkingEnd);
                            });
                    })
                    ->count();

                // Kiểm tra số reservation đang active
                $activeReservations = \App\Models\Reservation::whereHas('slot', function ($q) use ($request) {
                    $q->where('parking_lot_id', $request->parking_lot_id)
                        ->where('vehicle_type', $request->vehicle_type);
                })
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->count();

                // Kiểm tra số slot có conflict
                $conflictingSlotIds = \App\Models\Reservation::whereHas('slot', function ($q) use ($request) {
                    $q->where('parking_lot_id', $request->parking_lot_id)
                        ->where('vehicle_type', $request->vehicle_type);
                })
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where(function ($q) use ($parkingStart, $parkingEnd) {
                        $q->where('end_time', '>', $parkingStart)
                            ->where('start_time', '<', $parkingEnd);
                    })
                    ->pluck('slot_id')
                    ->unique()
                    ->toArray();

                $conflictingSlotsCount = count($conflictingSlotIds);
                $calculatedAvailable = $totalSlots - $conflictingSlotsCount;

                // Debug: Lấy một vài reservation để xem thời gian
                $sampleReservations = \App\Models\Reservation::whereHas('slot', function ($q) use ($request) {
                    $q->where('parking_lot_id', $request->parking_lot_id)
                        ->where('vehicle_type', $request->vehicle_type);
                })
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->limit(5)
                    ->get(['slot_id', 'start_time', 'end_time', 'status']);

                // Debug: Kiểm tra timezone
                $requestStartLocal = $request->desired_start_time->format('Y-m-d H:i:s');
                $requestStartUTC = $parkingStart->format('Y-m-d H:i:s');
                $requestEndUTC = $parkingEnd->format('Y-m-d H:i:s');

                Log::warning("❌ Không tìm được slot", [
                    'request_id' => $request->id,
                    'gate_id' => $request->gate_id,
                    'vehicle_type' => $request->vehicle_type,
                    'parking_lot_id' => $request->parking_lot_id,
                    'desired_start_time_local' => $requestStartLocal,
                    'desired_start_time_utc' => $requestStartUTC,
                    'desired_end_time_utc' => $requestEndUTC,
                    'duration_minutes' => $request->duration_minutes,
                    'total_slots_for_type' => $totalSlots,
                    'conflicting_slots_count' => $conflictingSlotsCount,
                    'available_slots_whereDoesntHave' => $availableSlots,
                    'calculated_available' => $calculatedAvailable,
                    'active_reservations' => $activeReservations,
                    'sample_reservations' => $sampleReservations->map(function ($r) {
                        return [
                            'slot_id' => $r->slot_id,
                            'start_time' => $r->start_time->format('Y-m-d H:i:s'),
                            'end_time' => $r->end_time->format('Y-m-d H:i:s'),
                            'status' => $r->status,
                        ];
                    })->toArray(),
                ]);

                $processingTime = (microtime(true) - $startTime) * 1000;
                $request->update([
                    'status' => 'failed',
                    'processing_time_ms' => round($processingTime, 2),
                    'processed_at' => now()
                ]);
                return null;
            }

            // QUAN TRỌNG: Double-check conflict trước khi commit (tránh race condition)
            // Có thể có request khác đã tạo reservation cho slot này trong lúc xử lý
            $hasConflict = \App\Models\Reservation::where('slot_id', $bestSlot->id)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where(function ($q) use ($parkingStart, $parkingEnd) {
                    $q->where('end_time', '>', $parkingStart)
                        ->where('start_time', '<', $parkingEnd);
                })
                ->where('reservation_request_id', '!=', $request->id) // Loại trừ reservation của chính request này
                ->exists();

            if ($hasConflict) {
                // Slot đã bị conflict, tìm slot khác hoặc fail
                Log::warning("⚠️ Slot conflict detected after allocation, retrying...", [
                    'request_id' => $request->id,
                    'slot_id' => $bestSlot->id,
                    'slot_code' => $bestSlot->slot_code
                ]);

                // Retry strategy: Thử nhiều lần với các điều kiện khác nhau
                $retrySlot = null;

                // Lần 1: Thử lại với gate_id ban đầu (có thể slot khác đã được giải phóng)
                $retrySlot = self::allocateBestSlotTransactional(
                    $request->parking_lot_id,
                    $request->vehicle_type,
                    $parkingStart,
                    $parkingEnd,
                    $request->gate_id
                );

                // Lần 2: Nếu không tìm được, bỏ qua gate_id để có nhiều lựa chọn hơn
                if (!$retrySlot) {
                    $retrySlot = self::allocateBestSlotTransactional(
                        $request->parking_lot_id,
                        $request->vehicle_type,
                        $parkingStart,
                        $parkingEnd,
                        null // Bỏ qua gate_id để có nhiều lựa chọn hơn
                    );
                }

                if (!$retrySlot) {
                    // Không tìm được slot nào khác - đây là kết quả tự nhiên khi có ít slot available
                    $processingTime = (microtime(true) - $startTime) * 1000;
                    $request->update([
                        'status' => 'failed',
                        'processing_time_ms' => round($processingTime, 2),
                        'processed_at' => now()
                    ]);
                    return null;
                }

                $bestSlot = $retrySlot;

                // Double-check lại lần nữa sau khi retry
                $hasConflictAfterRetry = \App\Models\Reservation::where('slot_id', $bestSlot->id)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where(function ($q) use ($parkingStart, $parkingEnd) {
                        $q->where('end_time', '>', $parkingStart)
                            ->where('start_time', '<', $parkingEnd);
                    })
                    ->where('reservation_request_id', '!=', $request->id)
                    ->exists();

                if ($hasConflictAfterRetry) {
                    // Vẫn có conflict sau retry, fail request
                    $processingTime = (microtime(true) - $startTime) * 1000;
                    $request->update([
                        'status' => 'failed',
                        'processing_time_ms' => round($processingTime, 2),
                        'processed_at' => now()
                    ]);
                    return null;
                }
            }

            // Cập nhật request với slot được cấp
            $processingTime = (microtime(true) - $startTime) * 1000;
            $request->update([
                'allocated_slot_id' => $bestSlot->id,
                'processing_time_ms' => round($processingTime, 2),
                'status' => 'assigned',
                'processed_at' => now()
            ]);

            // Lấy khoảng cách từ slot_gate_distances
            $minDistance = DB::table('slot_gate_distances')
                ->where('slot_id', $bestSlot->id)
                ->min('distance');

            Log::info("✅ Allocated slot successfully", [
                'request_id' => $request->id,
                'slot_code' => $bestSlot->slot_code,
                'distance' => $minDistance . 'm',
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
     * Tìm slot tốt nhất (gần cổng được chọn nhất, không conflict) trong transaction
     *
     * Logic overlap: 2 khoảng thời gian overlap nếu:
     * existing.end_time > new.start_time AND existing.start_time < new.end_time
     *
     * @param int $parkingLotId ID bãi đỗ
     * @param string $vehicleType Loại xe
     * @param Carbon $parkingStart Thời gian bắt đầu đỗ
     * @param Carbon $parkingEnd Thời gian kết thúc đỗ
     * @param int|null $gateId ID cổng được chọn (nếu có)
     * @return ParkingSlot|null Slot tốt nhất, null nếu không có
     */
    private static function allocateBestSlotTransactional(
        int $parkingLotId,
        string $vehicleType,
        Carbon $parkingStart,
        Carbon $parkingEnd,
        ?int $gateId = null
    ): ?ParkingSlot {
        return DB::transaction(function () use ($parkingLotId, $vehicleType, $parkingStart, $parkingEnd, $gateId) {
            // Chuyển về UTC để so sánh nhất quán với DB
            $parkingStart = $parkingStart->copy()->utc();
            $parkingEnd = $parkingEnd->copy()->utc();

            // BƯỚC 1: Tìm tất cả slot cùng loại xe
            $baseQuery = ParkingSlot::where('parking_lot_id', $parkingLotId)
                ->where('vehicle_type', $vehicleType);

            // BƯỚC 2: Lọc slot không có reservation overlap
            // Lấy danh sách slot_id có conflict
            $conflictingSlotIds = \App\Models\Reservation::whereHas('slot', function ($q) use ($parkingLotId, $vehicleType) {
                $q->where('parking_lot_id', $parkingLotId)
                    ->where('vehicle_type', $vehicleType);
            })
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where(function ($q) use ($parkingStart, $parkingEnd) {
                    // Logic overlap: overlap nếu existing.end > new.start AND existing.start < new.end
                    $q->where('end_time', '>', $parkingStart)
                        ->where('start_time', '<', $parkingEnd);
                })
                ->pluck('slot_id')
                ->unique()
                ->toArray();

            // Loại bỏ các slot có conflict
            if (!empty($conflictingSlotIds)) {
                $baseQuery->whereNotIn('parking_slots.id', $conflictingSlotIds);
            }

            // DEBUG: Kiểm tra số slot sau khi lọc
            $totalAfterFilter = (clone $baseQuery)->count();

            // BƯỚC 3: Nếu có gate_id, tìm slot gần cổng đó nhất
            if ($gateId) {
                // Kiểm tra xem có distance data không
                $hasDistanceData = DB::table('slot_gate_distances')
                    ->where('gate_id', $gateId)
                    ->exists();

                if ($hasDistanceData) {
                    $baseQuery->leftJoin('slot_gate_distances', function ($join) use ($gateId) {
                        $join->on('parking_slots.id', '=', 'slot_gate_distances.slot_id')
                            ->where('slot_gate_distances.gate_id', '=', $gateId);
                    })
                        ->select('parking_slots.*', 'slot_gate_distances.distance as gate_distance')
                        ->orderBy('gate_distance', 'asc');
                } else {
                    // Nếu không có dữ liệu distance cho gate này, sắp xếp theo ID (fallback)
                    $baseQuery->orderBy('parking_slots.id', 'asc');
                }
            } else {
                // Nếu không có gate_id, tìm slot gần cổng gần nhất
                $hasAnyDistanceData = DB::table('slot_gate_distances')->exists();

                if ($hasAnyDistanceData) {
                    $baseQuery->leftJoin('slot_gate_distances', 'parking_slots.id', '=', 'slot_gate_distances.slot_id')
                        ->select('parking_slots.*', DB::raw('MIN(slot_gate_distances.distance) as min_gate_distance'))
                        ->groupBy('parking_slots.id')
                        ->orderBy('min_gate_distance', 'asc');
                } else {
                    // Nếu không có dữ liệu distance, sắp xếp theo ID (fallback)
                    $baseQuery->orderBy('parking_slots.id', 'asc');
                }
            }

            // DEBUG: Log query SQL để kiểm tra
            if (config('app.debug')) {
                $sql = $baseQuery->toSql();
                $bindings = $baseQuery->getBindings();
                Log::debug("Allocation query", [
                    'sql' => $sql,
                    'bindings' => $bindings,
                    'total_after_filter' => $totalAfterFilter ?? 'N/A',
                ]);
            }

            $slot = $baseQuery->lockForUpdate()->first();

            // DEBUG: Nếu không tìm được slot, log thêm thông tin
            if (!$slot && config('app.debug')) {
                // Thử query không có gate_id để xem có slot nào không
                $queryWithoutGate = ParkingSlot::where('parking_lot_id', $parkingLotId)
                    ->where('vehicle_type', $vehicleType);
                if (!empty($conflictingSlotIds)) {
                    $queryWithoutGate->whereNotIn('id', $conflictingSlotIds);
                }
                $slotsWithoutGate = $queryWithoutGate->count();

                Log::debug("No slot found", [
                    'total_slots' => ParkingSlot::where('parking_lot_id', $parkingLotId)->where('vehicle_type', $vehicleType)->count(),
                    'conflicting_slots' => count($conflictingSlotIds ?? []),
                    'available_without_gate' => $slotsWithoutGate,
                    'gate_id' => $gateId,
                ]);
            }

            return $slot;
        }, 3); // Retry 3 lần nếu deadlock
    }
}
