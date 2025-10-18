<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reservation\ReservationStoreRequest;
use App\Models\PricingRule;
use App\Models\ReservationRequest;
use App\Models\Reservation;
use App\Services\SlotAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * @OA\Tag(
 *     name="🎫 Reservations",
 *     description="Quản lý đặt chỗ và cấp chỗ tự động"
 * )
 */
class ReservationController extends Controller
{
    protected $slotAllocationService;

    public function __construct(SlotAllocationService $slotAllocationService)
    {
        $this->slotAllocationService = $slotAllocationService;
    }

    /**
     * @OA\Post(
     *     path="/reservations",
     *     tags={"🎫 Reservations"},
     *     summary="Đặt chỗ với thuật toán cấp chỗ tự động",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parking_lot_id", "vehicle_type"},
     *             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="vehicle_id", type="integer", example=1),
     *             @OA\Property(property="vehicle_type", type="string", enum={"motorbike","car_4_seat","car_7_seat","light_truck"}),
     *             @OA\Property(property="algorithm", type="string", enum={"priority_queue","hungarian"}, example="priority_queue")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Đặt chỗ thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đặt chỗ thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ hoặc không có chỗ trống"
     *     )
     * )
     */
    public function store(ReservationStoreRequest $request)
    {
        $validated = $request->validated();

        // Tạo reservation request
        $reservationRequest = ReservationRequest::create([
            'parking_lot_id' => $validated['parking_lot_id'],
            'user_id' => $validated['user_id'] ?? null,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'vehicle_type' => $validated['vehicle_type'],
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        // Chọn thuật toán cấp chỗ
        $algorithm = $validated['algorithm'] ?? 'priority_queue';

        $allocatedSlot = match ($algorithm) {
            'hungarian' => $this->slotAllocationService->allocateSlotWithHungarian($reservationRequest),
            default => $this->slotAllocationService->allocateSlotWithPriorityQueue($reservationRequest)
        };

        if (!$allocatedSlot) {
            $reservationRequest->update(['status' => 'failed']);
            return response()->json([
                'success' => false,
                'message' => 'Không có chỗ trống phù hợp với loại xe này'
            ], 422);
        }

        // Tạo reservation record
        $reservation = Reservation::create([
            'user_id' => $reservationRequest->user_id,
            'vehicle_id' => $reservationRequest->vehicle_id,
            'slot_id' => $allocatedSlot->id,
            'reservation_code' => $this->generateReservationCode(),
            'status' => 'confirmed',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15), // Giữ chỗ 15 phút
            'user_snapshot' => $this->getUserSnapshot($reservationRequest->user_id),
            'vehicle_snapshot' => $this->getVehicleSnapshot($reservationRequest->vehicle_id),
            'pricing_snapshot' => $this->getPricingSnapshot($reservationRequest->parking_lot_id, $reservationRequest->vehicle_type)
        ]);

        // Cập nhật status của request
        $reservationRequest->update(['status' => 'assigned']);

        return response()->json([
            'success' => true,
            'message' => 'Đặt chỗ thành công',
            'data' => [
                'reservation' => $reservation,
                'allocated_slot' => $allocatedSlot,
                'algorithm_used' => $algorithm,
                'processing_time_ms' => $reservationRequest->processing_time_ms
            ]
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/reservations/{id}/extend",
     *     tags={"🎫 Reservations"},
     *     summary="Gia hạn đặt chỗ 1 lần (15 phút)",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID reservation"),
     *     @OA\Response(response=200, description="Gia hạn thành công"),
     *     @OA\Response(response=422, description="Không thể gia hạn")
     * )
     */
    public function extend($id)
    {
        $reservation = Reservation::findOrFail($id);
        $extendMinutes = 15;

        // Kiểm tra điều kiện gia hạn
        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể gia hạn reservation đang confirmed'
            ], 422);
        }

        if ($reservation->extended_at) {
            return response()->json([
                'success' => false,
                'message' => 'Đã gia hạn 1 lần, không thể gia hạn thêm'
            ], 422);
        }

        // Kiểm tra reservation chưa hết hạn
        if ($reservation->expires_at <= now()) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation đã hết hạn, không thể gia hạn'
            ], 422);
        }

        // Gia hạn thêm 15 phút
        $reservation->update([
            'extended_at' => now(),
            'expires_at' => $reservation->expires_at->addMinutes($extendMinutes)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gia hạn thành công',
            'data' => [
                'reservation' => $reservation,
                'new_expires_at' => $reservation->expires_at,
                'extended_minutes' => $extendMinutes
            ]
        ]);
    }

    /**
     * @OA\Put(
     *     path="/reservations/{id}/cancel",
     *     tags={"🎫 Reservations"},
     *     summary="Hủy đặt chỗ",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID reservation"),
     *     @OA\Response(response=200, description="Hủy thành công")
     * )
     */
    public function cancel($id)
    {
        $reservation = Reservation::findOrFail($id);

        // Cập nhật trạng thái reservation
        $reservation->update([
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);

        // Giải phóng slot
        if ($reservation->slot) {
            $reservation->slot->update(['status' => 'available']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hủy đặt chỗ thành công',
            'data' => $reservation
        ]);
    }

    /**
     * @OA\Get(
     *     path="/reservations/{id}",
     *     tags={"🎫 Reservations"},
     *     summary="Chi tiết đặt chỗ",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID reservation"),
     *     @OA\Response(response=200, description="Chi tiết reservation")
     * )
     */
    public function show($id)
    {
        $reservation = Reservation::with(['slot.parkingLot'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $reservation
        ]);
    }

    // Helper methods
    private function generateReservationCode(): string
    {
        return 'RES-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');
    }

    private function getUserSnapshot(?int $userId): ?array
    {
        if (!$userId)
            return null;

        // Mock data - trong thực tế sẽ gọi API svc-auth
        return [
            'id' => $userId,
            'name' => 'Nguyễn Văn A',
            'email' => 'user@example.com',
            'phone' => '0123456789'
        ];
    }

    private function getVehicleSnapshot(?int $vehicleId): ?array
    {
        if (!$vehicleId)
            return null;

        // Mock data - trong thực tế sẽ gọi API svc-auth
        return [
            'id' => $vehicleId,
            'plate' => '29A-12345',
            'type' => 'motorbike'
        ];
    }

    private function getPricingSnapshot(int $parkingLotId, string $vehicleType): array
    {
        $pricingRule = PricingRule::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->first();

        return $pricingRule ? $pricingRule->toArray() : [];
    }
}
