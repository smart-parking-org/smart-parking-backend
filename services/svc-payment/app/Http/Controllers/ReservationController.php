<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Reservation;
use Illuminate\Support\Str;
use App\Models\PricingRule;
use Illuminate\Http\Request;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\DB;
use App\Services\TimeOverlapService;
use App\Services\SlotAllocationService;
use App\Http\Requests\Reservation\ReservationStoreRequest;

/**
 * @OA\Tag(
 *     name="🎫 Reservations",
 *     description="Quản lý đặt chỗ và cấp chỗ tự động"
 * )
 */
class ReservationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/reservations",
     *     tags={"🎫 Reservations"},
     *     summary="Danh sách reservations với filter",
     *     description="Lấy danh sách reservations với các filter: user_id, vehicle_type, status, date_range. Hỗ trợ pagination.",
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filter theo user ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="vehicle_type",
     *         in="query",
     *         description="Filter theo loại xe",
     *         @OA\Schema(type="string", enum={"motorbike","car_4_seat","car_7_seat","light_truck"})
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter theo trạng thái",
     *         @OA\Schema(type="string", enum={"confirmed","checked_in","checked_out","cancelled","expired"})
     *     ),
     *     @OA\Parameter(
     *         name="parking_lot_id",
     *         in="query",
     *         description="Filter theo bãi đỗ",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Từ ngày (ISO 8601)",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Đến ngày (ISO 8601)",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Trang hiện tại",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Số bản ghi mỗi trang",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách reservations",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="reservations",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=101),
     *                         @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019"),
     *                         @OA\Property(property="status", type="string", example="checked_in"),
     *                         @OA\Property(property="plate", type="string", example="29A-12345"),
     *                         @OA\Property(property="reserved_at", type="string", format="date-time"),
     *                         @OA\Property(property="check_in_at", type="string", format="date-time"),
     *                         @OA\Property(property="check_out_at", type="string", format="date-time"),
     *                         @OA\Property(property="duration_minutes", type="integer", example=150),
     *                         @OA\Property(
     *                             property="slot",
     *                             type="object",
     *                             @OA\Property(property="slot_code", type="string", example="C4-012"),
     *                             @OA\Property(property="vehicle_type", type="string", example="car_4_seat")
     *                         ),
     *                         @OA\Property(
     *                             property="user_snapshot",
     *                             type="object",
     *                             @OA\Property(property="name", type="string", example="Nguyễn Văn A"),
     *                             @OA\Property(property="phone", type="string", example="0123456789")
     *                         )
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="pagination",
     *                     type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=15),
     *                     @OA\Property(property="total", type="integer", example=45),
     *                     @OA\Property(property="last_page", type="integer", example=3)
     *                 ),
     *                 @OA\Property(
     *                     property="summary",
     *                     type="object",
     *                     @OA\Property(property="total_reservations", type="integer", example=45),
     *                     @OA\Property(property="confirmed", type="integer", example=12),
     *                     @OA\Property(property="checked_in", type="integer", example=8),
     *                     @OA\Property(property="checked_out", type="integer", example=20),
     *                     @OA\Property(property="cancelled", type="integer", example=3),
     *                     @OA\Property(property="expired", type="integer", example=2)
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $query = Reservation::with(['slot', 'reservationRequest']);

        // Filter theo user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter theo vehicle_type
        if ($request->filled('vehicle_type')) {
            $query->whereHas('slot', function ($q) use ($request) {
                $q->where('vehicle_type', $request->vehicle_type);
            });
        }

        // Filter theo status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter theo parking_lot_id
        if ($request->filled('parking_lot_id')) {
            $query->whereHas('slot', function ($q) use ($request) {
                $q->where('parking_lot_id', $request->parking_lot_id);
            });
        }

        // Filter theo date range
        if ($request->filled('date_from')) {
            $query->where('reserved_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('reserved_at', '<=', $request->date_to);
        }

        // Sort mặc định: mới nhất trước
        $query->orderBy('reserved_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $reservations = $query->paginate($perPage);

        // Summary statistics
        $summary = [
            'total_reservations' => Reservation::count(),
            'confirmed' => Reservation::where('status', 'confirmed')->count(),
            'checked_in' => Reservation::where('status', 'checked_in')->count(),
            'checked_out' => Reservation::where('status', 'checked_out')->count(),
            'cancelled' => Reservation::where('status', 'cancelled')->count(),
            'expired' => Reservation::where('status', 'expired')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'reservations' => $reservations->items(),
                'pagination' => [
                    'current_page' => $reservations->currentPage(),
                    'per_page' => $reservations->perPage(),
                    'total' => $reservations->total(),
                    'last_page' => $reservations->lastPage(),
                ],
                'summary' => $summary
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/reservations",
     *     tags={"🎫 Reservations"},
     *     summary="Đặt chỗ với thuật toán cấp chỗ tự động",
     *     description="Yêu cầu các trường: parking_lot_id, user_id, vehicle_id, vehicle_type, desired_start_time, duration_minutes. Thuật toán mặc định priority_queue.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parking_lot_id","user_id","vehicle_id","vehicle_type","desired_start_time","duration_minutes"},
     *             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=12),
     *             @OA\Property(property="vehicle_id", type="integer", example=34),
     *             @OA\Property(property="vehicle_type", type="string", enum={"motorbike","car_4_seat","car_7_seat","light_truck"}, example="car_4_seat"),
     *             @OA\Property(property="desired_start_time", type="string", format="date-time", example="2025-10-19T09:30:00+07:00"),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=30, maximum=1440, example=120),
     *             @OA\Property(property="algorithm", type="string", enum={"priority_queue","hungarian"}, example="priority_queue", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Đặt chỗ thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đặt chỗ thành công"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="reservation",
     *                     type="object",
     *                     description="Bản ghi reservation đã tạo",
     *                     @OA\Property(property="id", type="integer", example=101),
     *                     @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019"),
     *                     @OA\Property(property="status", type="string", example="confirmed"),
     *                     @OA\Property(property="reserved_at", type="string", format="date-time"),
     *                     @OA\Property(property="expires_at", type="string", format="date-time"),
     *                     @OA\Property(property="slot_id", type="integer", example=55),
     *                     @OA\Property(property="reservation_request_id", type="integer", example=2001)
     *                 ),
     *                 @OA\Property(
     *                     property="allocated_slot",
     *                     type="object",
     *                     description="Chỗ được cấp",
     *                     @OA\Property(property="id", type="integer", example=55),
     *                     @OA\Property(property="slot_code", type="string", example="C4-012"),
     *                     @OA\Property(property="vehicle_type", type="string", example="car_4_seat")
     *                 ),
     *                 @OA\Property(property="algorithm_used", type="string", example="priority_queue"),
     *                 @OA\Property(property="processing_time_ms", type="number", example=12.35),
     *                 @OA\Property(property="qr_payload", type="string", example="RES-AB12CD34-20251019")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ hoặc không có chỗ trống",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Không có chỗ trống phù hợp với loại xe này")
     *         )
     *     )
     * )
     */
    public function store(ReservationStoreRequest $request)
    {
        $validated = $request->validated();

        // Chuẩn hóa tham số
        $parkingLotId = (int) $validated['parking_lot_id'];
        $vehicleType = $validated['vehicle_type'];
        $desiredStart = $validated['desired_start_time'];
        $duration = (int) $validated['duration_minutes'];
        $userId = (int) $validated['user_id'];
        $vehicleId = (int) $validated['vehicle_id'];
        $algorithm = $validated['algorithm'] ?? 'priority_queue';

        if ($vehicleId) {
            $hasActiveReservationByVehicle = Reservation::where('vehicle_id', $vehicleId)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->exists();
            if ($hasActiveReservationByVehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Xe này đang có đặt chỗ đang hiệu lực'
                ], 422);
            }
        }

        $MAX_ACTIVE_PER_USER = 3;
        if ($userId) {
            $activeCount = Reservation::where('user_id', $userId)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->count();
            if ($activeCount >= $MAX_ACTIVE_PER_USER) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn đã đạt giới hạn số lượt đặt đang hiệu lực'
                ], 422);
            }
        }

        return DB::transaction(
            function () use ($parkingLotId, $vehicleType, $desiredStart, $duration, $userId, $vehicleId, $algorithm) {
                // 1. Tạo reservation request
                $reservationRequest = ReservationRequest::create([
                    'parking_lot_id' => $parkingLotId,
                    'user_id' => $userId,
                    'vehicle_id' => $vehicleId,
                    'vehicle_type' => $vehicleType,
                    'desired_start_time' => $desiredStart,
                    'duration_minutes' => $duration,
                    'status' => 'pending',
                    'requested_at' => now(),
                ]);

                // 2. Chọn thuật toán cấp chỗ
                $allocatedSlot = match ($algorithm) {
                    'hungarian' => SlotAllocationService::allocateSlotWithHungarian($reservationRequest),
                    default => SlotAllocationService::allocateSlotWithPriorityQueue($reservationRequest)
                };

                if (!$allocatedSlot) {
                    $reservationRequest->update(['status' => 'failed']);
                    return response()->json([
                        'success' => false,
                        'message' => 'Không có chỗ trống phù hợp với loại xe này'
                    ], 422);
                }

                // 3. Final guard: kiểm tra chồng lấn lần cuối ngay trước khi tạo reservation
                $start = Carbon::parse($desiredStart);
                $end = $start->copy()->addMinutes($duration);
                if (TimeOverlapService::hasOverlapOnSlot($allocatedSlot->id, $start, $end)) {
                    $reservationRequest->update(['status' => 'failed']);
                    return response()->json([
                        'success' => false,
                        'message' => 'Khung giờ đã bị trùng, vui lòng thử lại'
                    ], 422);
                }

                // 4. Tạo Reservation (confirmed) + giữ chỗ 15 phút
                $reservation = Reservation::create([
                    'user_id' => $reservationRequest->user_id,
                    'vehicle_id' => $reservationRequest->vehicle_id,
                    'slot_id' => $allocatedSlot->id,
                    'reservation_request_id' => $reservationRequest->id,
                    'reservation_code' => $this->generateReservationCode(),
                    'status' => 'confirmed',
                    'reserved_at' => now(),
                    'expires_at' => now()->addMinutes(15),
                    'user_snapshot' => $this->getUserSnapshot($reservationRequest->user_id),
                    'vehicle_snapshot' => $this->getVehicleSnapshot($reservationRequest->vehicle_id),
                    'pricing_snapshot' => $this->getPricingSnapshot($reservationRequest->parking_lot_id, $reservationRequest->vehicle_type)
                ]);

                // 5. Cập nhật Request → assigned
                $reservationRequest->update(['status' => 'assigned']);

                return response()->json([
                    'success' => true,
                    'message' => 'Đặt chỗ thành công',
                    'data' => [
                        'reservation' => $reservation,
                        'allocated_slot' => $allocatedSlot,
                        'algorithm_used' => $algorithm,
                        'processing_time_ms' => $reservationRequest->processing_time_ms,
                        'qr_payload' => $reservation->reservation_code, // dùng làm QR
                    ]
                ], 201);
            }
        );

    }

    /**
     * @OA\Put(
     *     path="/reservations/{id}/extend",
     *     tags={"🎫 Reservations"},
     *     summary="Gia hạn giữ chỗ thêm 15 phút (chỉ 1 lần)",
     *     description="Chỉ áp dụng khi reservation đang confirmed, chưa hết hạn và chưa gia hạn trước đó.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Gia hạn thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Gia hạn thành công"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reservation", type="object",
     *                     @OA\Property(property="id", type="integer", example=101),
     *                     @OA\Property(property="status", type="string", example="confirmed"),
     *                     @OA\Property(property="expires_at", type="string", format="date-time", example="2025-10-19T14:45:00Z"),
     *                     @OA\Property(property="extended_at", type="string", format="date-time", example="2025-10-19T14:30:00Z")
     *                 ),
     *                 @OA\Property(property="new_expires_at", type="string", format="date-time", example="2025-10-19T14:45:00Z"),
     *                 @OA\Property(property="extended_minutes", type="integer", example=15)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể gia hạn (không đúng trạng thái / đã gia hạn / đã hết hạn)",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Reservation đã hết hạn, không thể gia hạn")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation"
     *     )
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
     *     summary="Hủy đặt chỗ khi đang confirmed",
     *     description="Chỉ cho phép hủy khi reservation đang confirmed. Sau khi hủy, slot vẫn available (trừ khi đang occupied do lệch trạng thái).",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Hủy thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Hủy đặt chỗ thành công"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="status", type="string", example="cancelled"),
     *                 @OA\Property(property="cancelled_at", type="string", format="date-time", example="2025-10-19T13:05:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể hủy do không đúng trạng thái",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Chỉ có thể hủy khi reservation đang confirmed")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation"
     *     )
     * )
     */
    public function cancel($id)
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể hủy khi reservation đang confirmed'
            ], 422);
        }

        // Cập nhật trạng thái reservation
        $reservation->update([
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);

        // Giải phóng slot
        if ($reservation->slot && $reservation->slot->status === 'occupied') {
            $reservation->slot->update(['status' => 'available']);
        }

        $this->finalizeRequestIfAny($reservation, 'cancelled');

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
     *     summary="Chi tiết đặt chỗ đầy đủ",
     *     description="Lấy thông tin chi tiết reservation bao gồm slot, parking lot, reservation request và snapshots.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chi tiết reservation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019"),
     *                 @OA\Property(property="status", type="string", example="checked_in"),
     *                 @OA\Property(property="reserved_at", type="string", format="date-time", example="2025-10-19T14:30:00Z"),
     *                 @OA\Property(property="expires_at", type="string", format="date-time", example="2025-10-19T14:45:00Z"),
     *                 @OA\Property(property="extended_at", type="string", format="date-time", example="2025-10-19T14:35:00Z"),
     *                 @OA\Property(property="check_in_at", type="string", format="date-time", example="2025-10-19T15:00:00Z"),
     *                 @OA\Property(property="check_out_at", type="string", format="date-time", example="2025-10-19T17:30:00Z"),
     *                 @OA\Property(property="cancelled_at", type="string", format="date-time", example=null),
     *                 @OA\Property(property="plate", type="string", example="29A-12345"),
     *                 @OA\Property(property="duration_minutes", type="integer", example=150),
     *                 @OA\Property(property="is_active", type="boolean", example=true),
     *                 @OA\Property(
     *                     property="slot",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=55),
     *                     @OA\Property(property="slot_code", type="string", example="C4-012"),
     *                     @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *                     @OA\Property(property="position_x", type="integer", example=10),
     *                     @OA\Property(property="position_y", type="integer", example=5),
     *                     @OA\Property(property="status", type="string", example="occupied"),
     *                     @OA\Property(
     *                         property="parking_lot",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="B1 Basement"),
     *                         @OA\Property(property="gate_pos_x", type="integer", example=0),
     *                         @OA\Property(property="gate_pos_y", type="integer", example=0)
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="reservation_request",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=2001),
     *                     @OA\Property(property="desired_start_time", type="string", format="date-time", example="2025-10-19T15:00:00Z"),
     *                     @OA\Property(property="duration_minutes", type="integer", example=120),
     *                     @OA\Property(property="status", type="string", example="assigned"),
     *                     @OA\Property(property="priority_score", type="number", example=950.5),
     *                     @OA\Property(property="processing_time_ms", type="number", example=12.35)
     *                 ),
     *                 @OA\Property(
     *                     property="user_snapshot",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=12),
     *                     @OA\Property(property="name", type="string", example="Nguyễn Văn A"),
     *                     @OA\Property(property="email", type="string", example="user@example.com"),
     *                     @OA\Property(property="phone", type="string", example="0123456789")
     *                 ),
     *                 @OA\Property(
     *                     property="vehicle_snapshot",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=34),
     *                     @OA\Property(property="plate", type="string", example="29A-12345"),
     *                     @OA\Property(property="type", type="string", example="motorbike")
     *                 ),
     *                 @OA\Property(
     *                     property="pricing_snapshot",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *                     @OA\Property(property="base_price_per_hour", type="number", example=5000),
     *                     @OA\Property(property="peak_hour_multiplier", type="number", example=1.5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation"
     *     )
     * )
     */
    public function show($id)
    {
        $reservation = Reservation::with(['slot.parkingLot', 'reservationRequest'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $reservation
        ]);
    }


    /**
     * @OA\Put(
     *     path="/reservations/{id}/check-in",
     *     tags={"🎫 Reservations"},
     *     summary="Check-in vào bãi đỗ",
     *     description="Chuyển reservation từ confirmed sang checked_in. Slot sẽ chuyển sang occupied. Chỉ áp dụng khi reservation đang confirmed.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Check-in thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Check-in thành công"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="status", type="string", example="checked_in"),
     *                 @OA\Property(property="check_in_at", type="string", format="date-time", example="2025-10-19T15:30:00Z"),
     *                 @OA\Property(property="slot_id", type="integer", example=55)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể check-in do không đúng trạng thái",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Chỉ có thể check-in reservation đang confirmed")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation"
     *     )
     * )
     */
    public function checkIn($id)
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể check-in reservation đang confirmed'
            ], 422);
        }

        // Cập nhật reservation
        $reservation->update([
            'status' => 'checked_in',
            'check_in_at' => now()
        ]);

        // Slot chuyển sang occupied
        $reservation->slot->update(['status' => 'occupied']);

        return response()->json([
            'success' => true,
            'message' => 'Check-in thành công',
            'data' => $reservation
        ]);
    }

    /**
     * @OA\Put(
     *     path="/reservations/{id}/check-out",
     *     tags={"🎫 Reservations"},
     *     summary="Check-out khỏi bãi đỗ",
     *     description="Chuyển reservation từ checked_in sang checked_out. Slot sẽ chuyển về available và ReservationRequest được đóng.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Check-out thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Check-out thành công"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="status", type="string", example="checked_out"),
     *                 @OA\Property(property="check_in_at", type="string", format="date-time", example="2025-10-19T15:30:00Z"),
     *                 @OA\Property(property="check_out_at", type="string", format="date-time", example="2025-10-19T17:45:00Z"),
     *                 @OA\Property(property="slot_id", type="integer", example=55)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể check-out do không đúng trạng thái",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Chỉ có thể check-out reservation đang checked_in")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation"
     *     )
     * )
     */
    public function checkOut($id)
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'checked_in') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể check-out reservation đang checked_in'
            ], 422);
        }

        // Cập nhật reservation
        $reservation->update([
            'status' => 'checked_out',
            'check_out_at' => now()
        ]);

        // Slot chuyển về available
        $reservation->slot->update(['status' => 'available']);

        $this->finalizeRequestIfAny($reservation);

        return response()->json([
            'success' => true,
            'message' => 'Check-out thành công',
            'data' => $reservation
        ]);
    }


    /**
     * @OA\Post(
     *     path="/demo/check-in",
     *     tags={"🎫 Reservations"},
     *     summary="Demo check-in bằng reservation_code",
     *     description="Check-in bằng reservation_code thay vì ID, dễ demo hơn.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reservation_code"},
     *             @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Check-in thành công"),
     *     @OA\Response(response=404, description="Không tìm thấy reservation"),
     *     @OA\Response(response=422, description="Không thể check-in")
     * )
     */
    public function demoCheckIn(Request $request)
    {
        $validated = $request->validate([
            'reservation_code' => 'required|string'
        ]);

        $reservation = Reservation::where('reservation_code', $validated['reservation_code'])->first();

        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy reservation với mã này'
            ], 404);
        }

        return $this->checkIn($reservation->id);
    }

    /**
     * @OA\Post(
     *     path="/demo/check-out",
     *     tags={"🎫 Reservations"},
     *     summary="Demo check-out bằng reservation_code",
     *     description="Check-out bằng reservation_code thay vì ID, dễ demo hơn.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reservation_code"},
     *             @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Check-out thành công"),
     *     @OA\Response(response=404, description="Không tìm thấy reservation"),
     *     @OA\Response(response=422, description="Không thể check-out")
     * )
     */
    public function demoCheckOut(Request $request)
    {
        $validated = $request->validate([
            'reservation_code' => 'required|string'
        ]);

        $reservation = Reservation::where('reservation_code', $validated['reservation_code'])->first();

        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy reservation với mã này'
            ], 404);
        }

        return $this->checkOut($reservation->id);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/availability",
     *     tags={"🎫 Reservations"},
     *     summary="Kiểm tra chỗ trống theo khung giờ",
     *     description="Kiểm tra số slot khả dụng cho loại xe trong khung thời gian cụ thể. Hỗ trợ dropdown chọn vehicle_type.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của parking lot",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="vehicle_type",
     *         in="query",
     *         required=true,
     *         description="Loại xe (dropdown)",
     *         @OA\Schema(
     *             type="string",
     *             enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"},
     *             example="car_4_seat"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="start_time",
     *         in="query",
     *         required=true,
     *         description="Thời gian bắt đầu (ISO 8601). Mẹo: mở DevTools (F12) → Console và dán: new Date(Date.now() + 10*60*1000).toISOString()",
     *         @OA\Schema(type="string", format="date-time", example="2025-10-19T14:30:00Z")
     *     ),
     *     @OA\Parameter(
     *         name="duration_minutes",
     *         in="query",
     *         required=true,
     *         description="Số phút đỗ xe (30-1440 phút)",
     *         @OA\Schema(type="integer", minimum=30, maximum=1440, example=120)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Thông tin availability",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="parking_lot_id", type="integer", example=1),
     *                 @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *                 @OA\Property(
     *                     property="time_range",
     *                     type="object",
     *                     @OA\Property(property="start", type="string", format="date-time", example="2025-10-19T14:30:00Z"),
     *                     @OA\Property(property="end", type="string", format="date-time", example="2025-10-19T16:30:00Z")
     *                 ),
     *                 @OA\Property(property="available_slots", type="integer", example=5),
     *                 @OA\Property(
     *                     property="slots",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=55),
     *                         @OA\Property(property="slot_code", type="string", example="C4-012"),
     *                         @OA\Property(property="position_x", type="integer", example=10),
     *                         @OA\Property(property="position_y", type="integer", example=5)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="vehicle_type không hợp lệ")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy parking lot"
     *     )
     * )
     */
    public function checkAvailability(Request $request, int $parkingLotId)
    {
        $validated = $request->validate([
            'vehicle_type' => 'required|string|in:motorbike,car_4_seat,car_7_seat,light_truck',
            'start_time' => 'required|date',
            'duration_minutes' => 'required|integer|min:30|max:1440'
        ]);

        $start = Carbon::parse($validated['start_time']);
        $end = $start->copy()->addMinutes((int) $validated['duration_minutes']);

        $availableSlots = SlotAllocationService::findAvailableSlotsInTimeRange(
            $parkingLotId,
            $validated['vehicle_type'],
            $start,
            $end
        );

        return response()->json([
            'success' => true,
            'data' => [
                'parking_lot_id' => $parkingLotId,
                'vehicle_type' => $validated['vehicle_type'],
                'time_range' => [
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String()
                ],
                'available_slots' => $availableSlots->count(),
                'slots' => $availableSlots->map(function ($slot) {
                    return [
                        'id' => $slot->id,
                        'slot_code' => $slot->slot_code,
                        'position_x' => $slot->position_x,
                        'position_y' => $slot->position_y
                    ];
                })
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/reservations/expire-due",
     *     tags={"🎫 Reservations"},
     *     summary="Tự động hết hạn reservations quá hạn",
     *     description="Hết hạn tất cả reservations đã quá hạn giữ chỗ. Chạy tự động bằng schedule.",
     *     @OA\Response(
     *         response=200,
     *         description="Hết hạn thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đã hết hạn 5 reservations"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="expired_count", type="integer", example=5)
     *             )
     *         )
     *     )
     * )
     */
    public function expireDue()
    {
        $expiredCount = Reservation::where('status', 'confirmed')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired'
            ]);

        return response()->json([
            'success' => true,
            'message' => "Đã hết hạn {$expiredCount} reservations",
            'data' => ['expired_count' => $expiredCount]
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

    private function finalizeRequestIfAny(Reservation $reservation, $finalStatus = 'completed'): void
    {
        if ($reservation->reservation_request_id) {
            ReservationRequest::whereKey($reservation->reservation_request_id)
                ->whereIn('status', ['assigned', 'pending'])
                ->update([
                    'status' => $finalStatus,
                    'processed_at' => now(),
                ]);
        }
    }
}
