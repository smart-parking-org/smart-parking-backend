<?php

namespace App\Http\Controllers;

use App\Models\CheckoutCode;
use App\Models\ExtensionPolicy;
use App\Models\Payment;
use App\Models\PeakHour;
use App\Services\AuthService;
use App\Services\ParkingFeeService;
use App\Services\PriorityQueueSlotAllocationService;
use App\Services\VnpayService;
use App\Traits\PushNotification;
use Carbon\Carbon;
use App\Models\Reservation;
use Http;
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
    use PushNotification;
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

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
            $query->where('start_time', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('start_time', '<=', $request->date_to);
        }

        // Sort mặc định: mới nhất trước
        $query->orderBy('start_time', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $reservations = $query->paginate($perPage);

        // Summary statistics
        $summary = [
            'total_reservations' => Reservation::count(),
            'confirmed' => Reservation::where('status', 'confirmed')->count(),
            'checked_in' => Reservation::where('status', 'checked_in')->count(),
            'pending_checkout' => Reservation::where('status', 'pending_checkout')->count(),
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
     *             @OA\Property(property="user_id", type="integer", example=2),
     *             @OA\Property(property="vehicle_id", type="integer", example=1),
     *             @OA\Property(property="desired_start_time", type="string", format="date-time", example="2025-10-22T17:12:00.000000Z"),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=30, maximum=1440, example=120),
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
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=2),
     *                     @OA\Property(property="vehicle_id", type="integer", example=1),
     *                     @OA\Property(property="slot_id", type="integer", example=1),
     *                     @OA\Property(property="reservation_request_id", type="integer", example=2),
     *                     @OA\Property(property="reservation_code", type="string", example="RES-TDZKIUQ9-20251022"),
     *                     @OA\Property(property="status", type="string", example="confirmed"),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2025-10-22T17:12:00.000000Z"),
     *                     @OA\Property(property="end_time", type="string", format="date-time", example="2025-10-22T19:12:00.000000Z"),
     *                     @OA\Property(property="expires_at", type="string", format="date-time", example="2025-10-22T17:27:00.000000Z"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-22T17:11:10.000000Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-22T17:11:10.000000Z"),
     *                     @OA\Property(
     *                         property="user_snapshot",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=2),
     *                         @OA\Property(property="name", type="string", example="Trần Hoàng Kha"),
     *                         @OA\Property(property="email", type="string", example="khath2004@gmail.com"),
     *                         @OA\Property(property="phone", type="string", example="0342123564")
     *                     ),
     *                     @OA\Property(
     *                         property="vehicle_snapshot",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="license_plate", type="string", example="94K-123.45"),
     *                         @OA\Property(property="vehicle_type", type="string", example="motorbike")
     *                     ),
     *                     @OA\Property(
     *                         property="pricing_snapshot",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="parking_lot_id", type="integer", example=1),
     *                         @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *                         @OA\Property(property="hourly", type="integer", example=5000),
     *                         @OA\Property(property="rounding_minutes", type="integer", example=30),
     *                         @OA\Property(property="daily_cap", type="integer", example=50000),
     *                         @OA\Property(property="monthly_pass", type="integer", example=300000),
     *                         @OA\Property(property="peak_enabled", type="boolean", example=true),
     *                         @OA\Property(property="peak_multiplier", type="number", example=1.5),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z")
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="allocated_slot",
     *                     type="object",
     *                     description="Chỗ được cấp",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="parking_lot_id", type="integer", example=1),
     *                     @OA\Property(property="slot_code", type="string", example="MB-001"),
     *                     @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *                     @OA\Property(property="status", type="string", example="available"),
     *                     @OA\Property(property="position_x", type="string", example="10.806176400733000"),
     *                     @OA\Property(property="position_y", type="string", example="106.628667651080000"),
     *                     @OA\Property(property="distance_from_gate", type="string", example="0.00"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z"),
     *                     @OA\Property(property="effective_status", type="string", example="available"),
     *                     @OA\Property(
     *                         property="current_reservation",
     *                         type="object",
     *                         description="Reservation hiện tại của slot",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="user_id", type="integer", example=2),
     *                         @OA\Property(property="vehicle_id", type="integer", example=1),
     *                         @OA\Property(property="reservation_request_id", type="integer", example=2),
     *                         @OA\Property(property="slot_id", type="integer", example=1),
     *                         @OA\Property(property="reservation_code", type="string", example="RES-TDZKIUQ9-20251022"),
     *                         @OA\Property(property="status", type="string", example="confirmed"),
     *                         @OA\Property(property="start_time", type="string", format="date-time", example="2025-10-22T17:12:00.000000Z"),
     *                         @OA\Property(property="end_time", type="string", format="date-time", example="2025-10-22T19:12:00.000000Z"),
     *                         @OA\Property(property="expires_at", type="string", format="date-time", example="2025-10-22T17:27:00.000000Z"),
     *                         @OA\Property(property="extended_at", type="string", format="date-time", example=null),
     *                         @OA\Property(property="check_in_at", type="string", format="date-time", example=null),
     *                         @OA\Property(property="check_out_at", type="string", format="date-time", example=null),
     *                         @OA\Property(property="cancelled_at", type="string", format="date-time", example=null),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-22T17:11:10.000000Z"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-22T17:11:10.000000Z"),
     *                         @OA\Property(
     *                             property="user_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=2),
     *                             @OA\Property(property="name", type="string", example="Nguyễn Văn A"),
     *                             @OA\Property(property="email", type="string", example="nguyevana@gmail.com"),
     *                             @OA\Property(property="phone", type="string", example="0342123564")
     *                         ),
     *                         @OA\Property(
     *                             property="vehicle_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *                             @OA\Property(property="license_plate", type="string", example="94K-123.45")
     *                         ),
     *                         @OA\Property(
     *                             property="pricing_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="hourly", type="integer", example=5000),
     *                             @OA\Property(property="daily_cap", type="integer", example=50000),
     *                             @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z"),
     *                             @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-22T17:09:59.000000Z"),
     *                             @OA\Property(property="monthly_pass", type="integer", example=300000),
     *                             @OA\Property(property="peak_enabled", type="boolean", example=true),
     *                             @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *                             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *                             @OA\Property(property="peak_multiplier", type="number", example=1.5),
     *                             @OA\Property(property="rounding_minutes", type="integer", example=30)
     *                         )
     *                     )
     *                 ),
     *                 @OA\Property(property="algorithm_used", type="string", example="priority_queue"),
     *                 @OA\Property(property="processing_time_ms", type="integer", example=302),
     *                 @OA\Property(property="qr_payload", type="string", example="RES-TDZKIUQ9-20251022")
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
        $desiredStart = $validated['desired_start_time'];
        $duration = (int) $validated['duration_minutes'];
        $userId = (int) $validated['user_id'];
        $vehicleId = (int) $validated['vehicle_id'];
        $algorithm = $validated['algorithm'] ?? 'priority_queue';

        // Kiểm tra user tồn tại và active
        if ($userId) {
            $userCheck = $this->authService->checkUserExists($userId);
            if (!$userCheck['exists']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Người dùng không tồn tại'
                ], 422);
            }

            if (!$userCheck['is_active']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản người dùng đã bị khóa'
                ], 422);
            }
        }

        // Kiểm tra vehicle tồn tại và active
        if ($vehicleId) {
            $vehicleCheck = $this->authService->checkVehicleExists($vehicleId);
            if (!$vehicleCheck['exists']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phương tiện không tồn tại'
                ], 422);
            }

            if (!$vehicleCheck['is_active']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phương tiện đã bị khóa'
                ], 422);
            }

            // Kiểm tra vehicle có thuộc về user không
            $vehicleData = $vehicleCheck['data'];
            if ($vehicleData['user']['id'] !== $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phương tiện không thuộc về người dùng này'
                ], 422);
            }

            // Kiểm tra vehicle có đang được sử dụng trong reservation khác không
            $hasActiveReservationByVehicle = Reservation::where('vehicle_id', $vehicleId)
                ->whereIn('status', ['confirmed', 'checked_in', 'pending_checkout'])
                ->exists();
            if ($hasActiveReservationByVehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Xe này đang có đặt chỗ đang hiệu lực'
                ], 422);
            }
        }

        // Kiểm tra giới hạn số reservation active per user
        $MAX_ACTIVE_PER_USER = 3;
        if ($userId) {
            $activeCount = Reservation::where('user_id', $userId)
                ->whereIn('status', ['confirmed', 'checked_in', 'pending_checkout'])
                ->count();
            if ($activeCount >= $MAX_ACTIVE_PER_USER) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn đã đạt giới hạn số lượt đặt đang hiệu lực'
                ], 422);
            }
        }

        $vehicleType = $vehicleData['vehicle_type'];

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
                $allocatedSlot = PriorityQueueSlotAllocationService::allocateSlot($reservationRequest);

                if (!$allocatedSlot) {
                    $reservationRequest->update(['status' => 'failed']);
                    return response()->json([
                        'success' => false,
                        'message' => 'Không có chỗ trống phù hợp với loại xe này'
                    ], 422);
                }

                // 3. Final guard: kiểm tra chồng lấn lần cuối ngay trước khi tạo reservation
                $start = Carbon::parse($desiredStart)->utc();
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
                    'start_time' => $start,
                    'end_time' => $start->copy()->addMinutes($duration),
                    'expires_at' => $start->copy()->addMinutes(15),
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

        // Kiểm tra điều kiện gia hạn
        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể gia hạn reservation đang confirmed'
            ], 422);
        }

        // Kiểm tra reservation chưa hết hạn
        if ($reservation->expires_at <= now()) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation đã hết hạn, không thể gia hạn'
            ], 422);
        }

        $parkingLotId = $reservation->slot->parking_lot_id;
        $extensionPolicy = ExtensionPolicy::getForParkingLot($parkingLotId);

        if (!$extensionPolicy || !$extensionPolicy->value['is_active']) {
            return response()->json([
                'success' => false,
                'message' => 'Chính sách gia hạn không được kích hoạt'
            ], 422);
        }

        // Đếm số lần đã gia hạn
        $currentExtensions = $reservation->extension_count ?? 0;

        // Kiểm tra có thể gia hạn thêm không
        if (!$extensionPolicy->canExtend($currentExtensions)) {
            return response()->json([
                'success' => false,
                'message' => "Đã đạt giới hạn số lần gia hạn ({$extensionPolicy->value['max_extensions']} lần)"
            ], 422);
        }

        $extendMinutes = $extensionPolicy->getExtensionMinutes();

        if (!$extensionPolicy || !$extensionPolicy->canExtend()) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể gia hạn theo chính sách hiện tại'
            ], 422);
        }

        // Gia hạn thêm 15 phút
        $reservation->update([
            'extended_at' => now(),
            'extension_count' => $currentExtensions + 1,
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
            'message' => 'Check-out thành công',
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
     *     description="Chuyển reservation từ checked_in sang pending_checkout (thanh toán online) hoặc checked_out (thanh toán trực tiếp).",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="payment_method", type="string", enum={"online", "offline"}, example="online", description="Phương thức thanh toán: online (tạo QR checkout) hoặc offline (thanh toán trực tiếp)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Check-out thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Check-out thành công"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="status", type="string", example="pending_checkout"),
     *                 @OA\Property(property="check_in_at", type="string", format="date-time", example="2025-10-19T15:30:00Z"),
     *                 @OA\Property(property="check_out_at", type="string", format="date-time", example="2025-10-19T17:45:00Z"),
     *                 @OA\Property(property="slot_id", type="integer", example=55),
     *                 @OA\Property(property="payment", type="object"),
     *                 @OA\Property(property="amount", type="integer", example=50000)
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
    public function checkOut(Request $request, $id)
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'checked_in') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể check-out reservation đang checked_in'
            ], 422);
        }

        $paymentMethod = $request->input('payment_method', 'online'); // Mặc định là online

        // Tính toán giá tiền dựa trên pricing snapshot
        $amount = $this->calculatePaymentAmount($reservation);

        // Tạo Payment
        $payment = Payment::create([
            'order_id' => $reservation->reservation_code,
            'reservation_id' => $reservation->id,
            'amount' => $amount,
            'txn_ref' => 'ORD' . now()->format('YmdHis') . rand(100, 999),
            'status' => 'PENDING',
            'meta' => [
                'payment_method' => $paymentMethod,
            ],
        ]);

        // Xác định status dựa trên payment method
        if ($paymentMethod === 'offline') {
            // Thanh toán trực tiếp → checked_out ngay
            $newStatus = 'checked_out';
            $reservation->slot->update(['status' => 'available']);
            $this->finalizeRequestIfAny($reservation);
        } else {
            // Thanh toán online → pending_checkout (chờ thanh toán và tạo QR)
            $newStatus = 'pending_checkout';
        }

        // Cập nhật reservation
        $reservation->update([
            'status' => $newStatus,
            'check_out_at' => now(),
            'payment_id' => $payment->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $paymentMethod === 'offline'
                ? 'Check-out thành công (thanh toán trực tiếp)'
                : 'Check-out thành công. Vui lòng thanh toán để nhận mã QR checkout.',
            'data' => [
                'reservation' => $reservation,
                'payment' => $payment,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
            ]
        ]);
    }

    private function calculatePaymentAmount(Reservation $reservation): int
    {
        $pricing = $reservation->pricing_snapshot;

        if (!$pricing || empty($pricing['hourly'])) {
            return 0;
        }

        $checkIn = Carbon::parse($reservation->check_in_at);
        // Dùng check_out_at nếu có, nếu không dùng thời điểm hiện tại
        $checkOut = $reservation->check_out_at
            ? Carbon::parse($reservation->check_out_at)
            : now();
        $durationMinutes = $checkIn->diffInMinutes($checkOut);

        // Làm tròn theo rounding_minutes (ví dụ: 30 phút)
        $roundingMinutes = $pricing['rounding_minutes'] ?? 30;
        $roundedMinutes = ceil($durationMinutes / $roundingMinutes) * $roundingMinutes;

        // Tính số giờ
        $hours = $roundedMinutes / 60;

        // Giá cơ bản
        $amount = $pricing['hourly'] * $hours;

        // Áp dụng peak hour multiplier nếu có
        if ($pricing['peak_enabled'] && $pricing['peak_multiplier']) {
            // Kiểm tra xem có rơi vào giờ cao điểm không
            $isPeakHour = $this->isPeakHour($checkIn, $checkOut, $reservation->slot->parking_lot_id);
            if ($isPeakHour) {
                $amount = $amount * $pricing['peak_multiplier'];
            }
        }

        // Áp dụng daily cap
        if ($pricing['daily_cap'] && $amount > $pricing['daily_cap']) {
            $amount = $pricing['daily_cap'];
        }

        return (int) ceil($amount);
    }

    private function isPeakHour(Carbon $checkIn, Carbon $checkOut, int $parkingLotId): bool
    {
        $peakHours = PeakHour::forParkingLot($parkingLotId)->get();

        foreach ($peakHours as $peak) {
            if (!$peak->is_active)
                continue;

            $dayOfWeek = $checkIn->dayOfWeek;
            if ($dayOfWeek != $peak->day_of_week)
                continue;

            $startTime = Carbon::parse($peak->start_time)->setDateFrom($checkIn);
            $endTime = Carbon::parse($peak->end_time)->setDateFrom($checkIn);

            // Kiểm tra có chồng lấn không
            if ($checkIn->lt($endTime) && $checkOut->gt($startTime)) {
                return true;
            }
        }

        return false;
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

        return $this->checkOut($request, $reservation->id);
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
        // Lấy các reservations cần expire (confirmed và quá expires_at)
        $expiredReservations = Reservation::where('status', 'confirmed')
            ->where('expires_at', '<', now())
            ->with(['slot', 'reservationRequest'])
            ->get();

        $expiredCount = 0;

        foreach ($expiredReservations as $reservation) {
            // Cập nhật status thành expired
            $reservation->update([
                'status' => 'expired'
            ]);

            // Giải phóng slot nếu đang occupied
            if ($reservation->slot && $reservation->slot->status === 'occupied') {
                $reservation->slot->update(['status' => 'available']);
            }

            // Finalize request
            $this->finalizeRequestIfAny($reservation, 'expired');

            $expiredCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Đã hết hạn {$expiredCount} reservations",
            'data' => ['expired_count' => $expiredCount]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/reservations/user/{user_id}/history",
     *     tags={"🎫 Reservations"},
     *     summary="Lấy toàn bộ lịch sử đặt chỗ của người dùng",
     *     description="Lấy lịch sử đặt chỗ đầy đủ thông tin bao gồm: thông tin bãi đỗ, slot, trạng thái thanh toán, và các thông tin chi tiết khác",
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         description="ID của người dùng",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="Filter theo trạng thái",
     *         @OA\Schema(type="string", enum={"confirmed","checked_in","checked_out","cancelled","expired"})
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=false,
     *         description="Từ ngày (ISO 8601)",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=false,
     *         description="Đến ngày (ISO 8601)",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Trang hiện tại",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Số bản ghi mỗi trang",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lịch sử đặt chỗ",
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
     *                         @OA\Property(property="status", type="string", example="checked_out"),
     *                         @OA\Property(property="start_time", type="string", format="date-time"),
     *                         @OA\Property(property="end_time", type="string", format="date-time"),
     *                         @OA\Property(property="expires_at", type="string", format="date-time", nullable=true),
     *                         @OA\Property(property="check_in_at", type="string", format="date-time", nullable=true),
     *                         @OA\Property(property="check_out_at", type="string", format="date-time", nullable=true),
     *                         @OA\Property(property="cancelled_at", type="string", format="date-time", nullable=true),
     *                         @OA\Property(property="duration_minutes", type="integer", nullable=true, example=150),
     *                         @OA\Property(property="extension_count", type="integer", example=0),
     *                         @OA\Property(
     *                             property="slot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=55),
     *                             @OA\Property(property="slot_code", type="string", example="C4-012"),
     *                             @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *                             @OA\Property(property="status", type="string", example="available"),
     *                             @OA\Property(
     *                                 property="parking_lot",
     *                                 type="object",
     *                                 @OA\Property(property="id", type="integer", example=1),
     *                                 @OA\Property(property="name", type="string", example="Tầng hầm B1"),
     *                                 @OA\Property(property="gate_pos_x", type="number", example=10.806176400733412),
     *                                 @OA\Property(property="gate_pos_y", type="number", example=106.6286676510779)
     *                             )
     *                         ),
     *                         @OA\Property(
     *                             property="user_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=12),
     *                             @OA\Property(property="name", type="string", example="Nguyễn Văn A"),
     *                             @OA\Property(property="email", type="string", example="user@example.com"),
     *                             @OA\Property(property="phone", type="string", example="0123456789")
     *                         ),
     *                         @OA\Property(
     *                             property="vehicle_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=34),
     *                             @OA\Property(property="license_plate", type="string", example="29A-12345"),
     *                             @OA\Property(property="vehicle_type", type="string", example="motorbike")
     *                         ),
     *                         @OA\Property(
     *                             property="pricing_snapshot",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *                             @OA\Property(property="hourly", type="integer", example=5000),
     *                             @OA\Property(property="daily_cap", type="integer", example=50000),
     *                             @OA\Property(property="monthly_pass", type="integer", example=300000)
     *                         ),
     *                         @OA\Property(
     *                             property="payment",
     *                             type="object",
     *                             nullable=true,
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="order_id", type="string", example="RES-AB12CD34-20251019"),
     *                             @OA\Property(property="amount", type="integer", example=50000),
     *                             @OA\Property(property="status", type="string", enum={"PENDING","PAID","FAILED"}, example="PAID"),
     *                             @OA\Property(property="txn_ref", type="string", example="ORD20251018093000123"),
     *                             @OA\Property(property="created_at", type="string", format="date-time")
     *                         ),
     *                         @OA\Property(property="created_at", type="string", format="date-time"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time")
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
     *                     @OA\Property(property="confirmed", type="integer", example=2),
     *                     @OA\Property(property="checked_in", type="integer", example=5),
     *                     @OA\Property(property="checked_out", type="integer", example=30),
     *                     @OA\Property(property="cancelled", type="integer", example=5),
     *                     @OA\Property(property="expired", type="integer", example=3),
     *                     @OA\Property(property="total_paid", type="integer", example=1500000),
     *                     @OA\Property(property="total_pending", type="integer", example=50000)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy user"
     *     )
     * )
     */
    public function getUserHistory(Request $request, $userId)
    {
        $query = Reservation::with(['slot.parkingLot'])
            ->where('user_id', $userId);

        // Filter theo status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter theo date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        // Sort mặc định: mới nhất trước
        $query->orderByDesc('created_at');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $reservations = $query->paginate($perPage);

        // Lấy thông tin payment cho mỗi reservation
        $reservations->getCollection()->transform(function ($reservation) {
            // Tìm payment theo order_id (có thể là reservation_code) hoặc trong meta
            $payment = Payment::where('order_id', $reservation->reservation_code)
                ->orWhere(function ($q) use ($reservation) {
                    $q->whereJsonContains('meta->reservation_id', $reservation->id);
                })
                ->orderByDesc('created_at')
                ->first();

            // Thêm payment vào reservation
            $reservation->payment = $payment;

            return $reservation;
        });

        // Summary statistics
        $summaryQuery = Reservation::where('user_id', $userId);
        $summary = [
            'total_reservations' => $summaryQuery->count(),
            'confirmed' => $summaryQuery->clone()->where('status', 'confirmed')->count(),
            'checked_in' => $summaryQuery->clone()->where('status', 'checked_in')->count(),
            'checked_out' => $summaryQuery->clone()->where('status', 'checked_out')->count(),
            'cancelled' => $summaryQuery->clone()->where('status', 'cancelled')->count(),
            'expired' => $summaryQuery->clone()->where('status', 'expired')->count(),
        ];

        // Tính tổng tiền đã thanh toán và đang chờ
        $paidPayments = Payment::whereIn('order_id', $reservations->pluck('reservation_code'))
            ->where('status', 'PAID')
            ->sum('amount');

        $pendingPayments = Payment::whereIn('order_id', $reservations->pluck('reservation_code'))
            ->where('status', 'PENDING')
            ->sum('amount');

        $summary['total_paid'] = (int) $paidPayments;
        $summary['total_pending'] = (int) $pendingPayments;

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

    // Helper methods

    private function checkVehicleExists(?int $vehicleId): bool
    {
        if (!$vehicleId)
            return false;

        $vehicle = Http::get(env('SVC_AUTH_URL') . "/api/vehicles/{$vehicleId}");

        // Mock check - trong thực tế sẽ gọi API svc-auth
        return true;
    }
    private function generateReservationCode(): string
    {
        return 'RES-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');
    }

    private function getUserSnapshot(?int $userId): ?array
    {
        if (!$userId)
            return null;

        return $this->authService->getUserSnapshot($userId);
    }

    private function getVehicleSnapshot(?int $vehicleId): ?array
    {
        if (!$vehicleId)
            return null;

        return $this->authService->getVehicleSnapshot($vehicleId);
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

    /**
     * @OA\Post(
     *     path="/reservations/scan-checkout",
     *     tags={"🎫 Reservations"},
     *     summary="Quét QR checkout code để hoàn tất checkout",
     *     description="Quét QR checkout code để chuyển reservation từ pending_checkout sang checked_out. Chỉ dành cho nhân viên hoặc hệ thống.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"checkout_code"},
     *             @OA\Property(property="checkout_code", type="string", example="CHK-ABC12345-20251110")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Checkout thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Checkout thành công"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(property="checkout_code", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy checkout code"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Checkout code không hợp lệ hoặc đã sử dụng"
     *     )
     *     )
     */
    public function scanCheckoutCode(Request $request)
    {
        $validated = $request->validate([
            'checkout_code' => 'required|string',
        ]);

        $checkoutCode = CheckoutCode::where('checkout_code', $validated['checkout_code'])->first();

        if (!$checkoutCode) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy mã checkout'
            ], 404);
        }

        // Kiểm tra mã còn hiệu lực không
        if (!$checkoutCode->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Mã checkout đã hết hạn hoặc đã được sử dụng'
            ], 422);
        }

        $reservation = $checkoutCode->reservation;

        // Kiểm tra reservation status
        if ($reservation->status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Reservation không ở trạng thái pending_checkout'
            ], 422);
        }

        // Finalize checkout
        $reservation->update([
            'status' => 'checked_out',
        ]);

        // Giải phóng slot
        $reservation->slot->update(['status' => 'available']);

        // Finalize request
        $this->finalizeRequestIfAny($reservation);

        // Đánh dấu checkout code đã sử dụng
        $checkoutCode->markAsUsed();

        return response()->json([
            'success' => true,
            'message' => 'Checkout thành công',
            'data' => [
                'reservation' => $reservation,
                'checkout_code' => $checkoutCode,
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/reservations/{id}/checkout-code",
     *     tags={"🎫 Reservations"},
     *     summary="Lấy QR checkout code cho reservation",
     *     description="Lấy QR checkout code đã được tạo sau khi thanh toán thành công. Chỉ áp dụng cho reservation có status = pending_checkout.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của reservation",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lấy checkout code thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="checkout_code", type="string", example="CHK-ABC12345-20251110"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(property="expires_at", type="string", format="date-time"),
     *                 @OA\Property(property="qr_data", type="string", description="Dữ liệu để tạo QR code")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation hoặc checkout code"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Reservation không ở trạng thái pending_checkout"
     *     )
     *     )
     */
    public function getCheckoutCode($id)
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Reservation không ở trạng thái pending_checkout'
            ], 422);
        }

        // Tìm checkout code active
        $checkoutCode = CheckoutCode::where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$checkoutCode) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa có mã checkout. Vui lòng thanh toán trước.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'checkout_code' => $checkoutCode->checkout_code,
                'status' => $checkoutCode->status,
                'expires_at' => $checkoutCode->expires_at?->toIso8601String(),
                'qr_data' => $checkoutCode->checkout_code, // Dữ liệu để tạo QR code
            ]
        ]);
    }
}
