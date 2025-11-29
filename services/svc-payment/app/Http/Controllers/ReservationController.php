<?php

namespace App\Http\Controllers;

use App\Models\CheckoutCode;
use App\Models\ExtensionPolicy;
use App\Models\MonthlyPass;
use App\Models\Payment;
use App\Models\PeakHour;
use App\Models\SlotGateDistance;
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
     *                             property="reservation_request",
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=2),
     *                             @OA\Property(property="gate_id", type="integer", example=1)
     *                         ),
     *                         @OA\Property(
     *                             property="gate",
     *                             type="object",
     *                             nullable=true,
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="gate_code", type="string", example="GATE-001"),
     *                             @OA\Property(property="gate_type", type="string", enum={"entrance", "exit", "both"}, example="entrance"),
     *                             @OA\Property(property="position_x", type="number", format="float", nullable=true),
     *                             @OA\Property(property="position_y", type="number", format="float", nullable=true),
     *                             @OA\Property(property="is_active", type="boolean", example=true)
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
        $query = Reservation::with(['slot.parkingLot', 'reservationRequest.gate', 'reservationRequest.parkingLot', 'payment']);

        // Filter theo user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter theo vehicle_type
        if ($request->filled('vehicle_type')) {
            $query->where(function ($q) use ($request) {
                // Nếu có slot → filter qua slot
                $q->whereHas('slot', function ($slotQuery) use ($request) {
                    $slotQuery->where('vehicle_type', $request->vehicle_type);
                })
                    // Nếu chưa có slot → filter qua reservation_request
                    ->orWhereHas('reservationRequest', function ($requestQuery) use ($request) {
                        $requestQuery->where('vehicle_type', $request->vehicle_type);
                    });
            });
        }

        // Filter theo status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter theo parking_lot_id
        if ($request->filled('parking_lot_id')) {
            $parkingLotId = (int) $request->parking_lot_id;
            $query->where(function ($q) use ($parkingLotId) {
                // Nếu có slot → filter qua slot.parking_lot_id
                $q->whereHas('slot', function ($slotQuery) use ($parkingLotId) {
                    $slotQuery->where('parking_lot_id', $parkingLotId);
                })
                    // Nếu chưa có slot → filter qua reservation_request.parking_lot_id
                    ->orWhereHas('reservationRequest', function ($requestQuery) use ($parkingLotId) {
                        $requestQuery->where('parking_lot_id', $parkingLotId);
                    });
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

        // Summary statistics (áp dụng cùng filter với query chính)
        $summaryQuery = Reservation::query();

        // Áp dụng filter parking_lot_id cho summary nếu có
        if ($request->filled('parking_lot_id')) {
            $parkingLotId = (int) $request->parking_lot_id;
            $summaryQuery->where(function ($q) use ($parkingLotId) {
                $q->whereHas('slot', function ($slotQuery) use ($parkingLotId) {
                    $slotQuery->where('parking_lot_id', $parkingLotId);
                })
                    ->orWhereHas('reservationRequest', function ($requestQuery) use ($parkingLotId) {
                        $requestQuery->where('parking_lot_id', $parkingLotId);
                    });
            });
        }

        $summary = [
            'total_reservations' => (clone $summaryQuery)->count(),
            'confirmed' => (clone $summaryQuery)->where('status', 'confirmed')->count(),
            'checked_in' => (clone $summaryQuery)->where('status', 'checked_in')->count(),
            'pending_checkout' => (clone $summaryQuery)->where('status', 'pending_checkout')->count(),
            'checked_out' => (clone $summaryQuery)->where('status', 'checked_out')->count(),
            'cancelled' => (clone $summaryQuery)->where('status', 'cancelled')->count(),
            'expired' => (clone $summaryQuery)->where('status', 'expired')->count(),
        ];

        // Transform reservations để đưa gate và parking_lot ra cùng cấp với reservation
        $transformedReservations = $reservations->getCollection()->map(function ($reservation) {
            $data = $reservation->toArray();

            // Tách gate ra khỏi reservation_request và đặt ở cùng cấp
            $gate = null;
            if (isset($data['reservation_request']['gate'])) {
                $gate = $data['reservation_request']['gate'];
                $data['gate'] = $gate;
                unset($data['reservation_request']['gate']);
            }

            // ✅ Lấy thông tin parking lot: từ slot nếu có, nếu không thì từ reservation_request
            $parkingLot = null;
            if (isset($data['slot']['parking_lot']) && $data['slot']['parking_lot']) {
                $parkingLot = $data['slot']['parking_lot'];
            } elseif (isset($data['reservation_request']['parking_lot']) && $data['reservation_request']['parking_lot']) {
                $parkingLot = $data['reservation_request']['parking_lot'];
            }

            // ✅ Đưa parking_lot ra cùng cấp với reservation
            if ($parkingLot) {
                $data['parking_lot'] = $parkingLot;
            }

            // ✅ Lấy khoảng cách từ slot đến cổng (nếu có slot và gate)
            $distanceFromGate = null;
            if (isset($data['slot']['id']) && $gate && isset($gate['id'])) {
                $slotGateDistance = SlotGateDistance::where('slot_id', $data['slot']['id'])
                    ->where('gate_id', $gate['id'])
                    ->first();

                if ($slotGateDistance) {
                    $distanceFromGate = (float) $slotGateDistance->distance;
                }
            }

            // ✅ Thêm khoảng cách vào response
            if ($distanceFromGate !== null) {
                $data['distance_from_gate_meters'] = $distanceFromGate;
            }

            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'reservations' => $transformedReservations->values()->all(),
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
     *     summary="Đặt chỗ (chưa gán slot cụ thể)",
     *     description="Yêu cầu các trường: parking_lot_id, user_id, vehicle_id, desired_start_time, duration_minutes. Reservation sẽ được tạo với status='confirmed' nhưng chưa có slot_id. Slot sẽ được gán khi check-in với gate_id.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parking_lot_id","user_id","vehicle_id","desired_start_time","duration_minutes"},
     *             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=2),
     *             @OA\Property(property="vehicle_id", type="integer", example=1),
     *             @OA\Property(property="desired_start_time", type="string", format="date-time", example="2025-10-22T17:12:00.000000Z"),
     *             @OA\Property(property="duration_minutes", type="integer", minimum=30, maximum=1440, example=120),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Đặt chỗ thành công (chưa gán slot)",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đặt chỗ thành công. Vui lòng check-in tại cổng để được cấp chỗ cụ thể."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="reservation",
     *                     type="object",
     *                     description="Bản ghi reservation đã tạo (chưa có slot_id)",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=2),
     *                     @OA\Property(property="vehicle_id", type="integer", example=1),
     *                     @OA\Property(property="slot_id", type="integer", nullable=true, example=null, description="Chưa có slot, sẽ được gán khi check-in"),
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
     *                 @OA\Property(property="allocated_slot", type="null", example=null, description="Chưa có slot, sẽ được gán khi check-in"),
     *                 @OA\Property(property="algorithm_used", type="null", example=null, description="Chưa chạy thuật toán"),
     *                 @OA\Property(property="processing_time_ms", type="null", example=null, description="Chưa chạy thuật toán"),
     *                 @OA\Property(property="qr_payload", type="string", example="RES-TDZKIUQ9-20251022", description="Dùng reservation_code để check-in")
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
        // gate_id không cần truyền lên khi đặt chỗ, sẽ truyền khi check-in
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
                \Log::info('Loi 1');
                return response()->json([
                    'success' => false,
                    'message' => 'Phương tiện không tồn tại'
                ], 422);
            }

            if (!$vehicleCheck['is_active']) {
                \Log::info('Loi 2');

                return response()->json([
                    'success' => false,
                    'message' => 'Phương tiện đã bị khóa'
                ], 422);
            }

            // Kiểm tra vehicle có thuộc về user không
            $vehicleData = $vehicleCheck['data'];
            if ($vehicleData['user']['id'] !== $userId) {
                \Log::info('Loi 3');

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
                \Log::info('Loi 4');

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
                \Log::info('Loi 5');

                return response()->json([
                    'success' => false,
                    'message' => 'Bạn đã đạt giới hạn số lượt đặt đang hiệu lực'
                ], 422);
            }
        }

        $vehicleType = $vehicleData['vehicle_type'];

        return DB::transaction(
            function () use ($parkingLotId, $vehicleType, $desiredStart, $duration, $userId, $vehicleId, $algorithm) {
                // ✅ Kiểm tra monthly pass
                $start = Carbon::parse($desiredStart)->utc();
                $end = $start->copy()->addMinutes($duration);

                // ✅ KIỂM TRA VÀ GIỮ CHỖ: Đảm bảo còn slot trống cho loại xe này
                $availableSlots = $this->checkAvailableSlotsForReservation($parkingLotId, $vehicleType, $start, $end);

                if ($availableSlots <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Không còn chỗ trống cho loại xe này trong khung giờ đã chọn'
                    ], 422);
                }

                // 1. Tạo reservation request (chưa có gate_id, status = pending)
                $reservationRequest = ReservationRequest::create([
                    'parking_lot_id' => $parkingLotId,
                    'user_id' => $userId,
                    'vehicle_id' => $vehicleId,
                    'vehicle_type' => $vehicleType,
                    'desired_start_time' => $desiredStart,
                    'duration_minutes' => $duration,
                    'gate_id' => null, // Chưa có gate_id, sẽ được cập nhật khi check-in
                    'status' => 'pending',
                    'requested_at' => now(),
                ]);

                $monthlyPass = MonthlyPass::findValidPass(
                    $userId,
                    $vehicleId,
                    $parkingLotId,
                    $start->toDateString()
                );

                // 2. Tạo Reservation (confirmed) nhưng CHƯA GÁN SLOT (slot_id = null)
                // Slot sẽ được gán khi check-in với gate_id
                // ✅ GIỮ CHỖ 15 PHÚT: expires_at để người dùng có thời gian đến cổng và check-in
                $reservation = Reservation::create([
                    'user_id' => $reservationRequest->user_id,
                    'vehicle_id' => $reservationRequest->vehicle_id,
                    'slot_id' => null, // Chưa gán slot, sẽ gán khi check-in
                    'reservation_request_id' => $reservationRequest->id,
                    'reservation_code' => $this->generateReservationCode(),
                    'status' => 'confirmed',
                    'start_time' => $start,
                    'end_time' => $end,
                    'expires_at' => $start->copy()->addMinutes(15), // ✅ Giữ chỗ 15 phút, có thể gia hạn
                    'user_snapshot' => $this->getUserSnapshot($reservationRequest->user_id),
                    'vehicle_snapshot' => $this->getVehicleSnapshot($reservationRequest->vehicle_id),
                    'pricing_snapshot' => $this->getPricingSnapshot($reservationRequest->parking_lot_id, $reservationRequest->vehicle_type)
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Đặt chỗ thành công. Vui lòng check-in tại cổng để được cấp chỗ cụ thể.',
                    'data' => [
                        'reservation' => $reservation,
                        'allocated_slot' => null, // Chưa có slot
                        'algorithm_used' => null, // Chưa chạy thuật toán
                        'processing_time_ms' => null,
                        'qr_payload' => $reservation->reservation_code, // dùng làm QR để check-in
                        'monthly_pass' => $monthlyPass && $monthlyPass->isValid($start->toDateString()) ? [
                            'id' => $monthlyPass->id,
                            'order_id' => $monthlyPass->order_id,
                            'end_date' => $monthlyPass->end_date?->toDateString(),
                            'message' => 'Vé tháng của bạn sẽ được áp dụng khi checkout (miễn phí)',
                        ] : null,
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

        // ✅ Lấy parking_lot_id: từ slot nếu có, nếu không thì từ reservation_request
        $parkingLotId = null;
        if ($reservation->slot_id !== null && $reservation->slot) {
            $parkingLotId = $reservation->slot->parking_lot_id;
        } elseif ($reservation->reservationRequest) {
            $parkingLotId = $reservation->reservationRequest->parking_lot_id;
        }

        if (!$parkingLotId) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xác định bãi đỗ cho reservation này'
            ], 422);
        }

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

        // ✅ Giải phóng slot (chỉ khi reservation đã có slot và slot đang occupied)
        // Nếu reservation chưa có slot (slot_id = null) thì không cần giải phóng
        if ($reservation->slot_id !== null && $reservation->slot && $reservation->slot->status === 'occupied') {
            $reservation->slot->update(['status' => 'available']);
        }

        // ✅ Finalize reservation request (cập nhật status thành cancelled)
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
     *                     @OA\Property(property="processing_time_ms", type="number", example=12.35),
     *                     @OA\Property(property="gate_id", type="integer", example=1)
     *                 ),
     *                 @OA\Property(
     *                     property="gate",
     *                     type="object",
     *                     nullable=true,
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="gate_code", type="string", example="GATE-001"),
     *                     @OA\Property(property="gate_type", type="string", enum={"entrance", "exit", "both"}, example="entrance"),
     *                     @OA\Property(property="position_x", type="number", format="float", nullable=true),
     *                     @OA\Property(property="position_y", type="number", format="float", nullable=true),
     *                     @OA\Property(property="is_active", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(
     *                     property="parking_lot",
     *                     type="object",
     *                     nullable=true,
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Tầng hầm B1"),
     *                     @OA\Property(property="gate_pos_x", type="number", nullable=true, example=10.806176400733),
     *                     @OA\Property(property="gate_pos_y", type="number", nullable=true, example=106.62866765108)
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
        $reservation = Reservation::with(['slot.parkingLot', 'reservationRequest.gate', 'reservationRequest.parkingLot', 'payment'])->findOrFail($id);

        // Transform để đưa gate và parking_lot ra cùng cấp với reservation
        $data = $reservation->toArray();

        // ✅ Tách gate ra khỏi reservation_request và đặt ở cùng cấp
        $gate = null;
        if (isset($data['reservation_request']['gate'])) {
            $gate = $data['reservation_request']['gate'];
            $data['gate'] = $gate;
            unset($data['reservation_request']['gate']);
        }

        // ✅ Lấy thông tin parking lot: từ slot nếu có, nếu không thì từ reservation_request
        $parkingLot = null;
        if (isset($data['slot']['parking_lot']) && $data['slot']['parking_lot']) {
            $parkingLot = $data['slot']['parking_lot'];
        } elseif (isset($data['reservation_request']['parking_lot']) && $data['reservation_request']['parking_lot']) {
            $parkingLot = $data['reservation_request']['parking_lot'];
        }

        // ✅ Đưa parking_lot ra cùng cấp với reservation
        if ($parkingLot) {
            $data['parking_lot'] = $parkingLot;
        }

        // ✅ Lấy khoảng cách từ slot đến cổng (nếu có slot và gate)
        $distanceFromGate = null;
        if (isset($data['slot']['id']) && $gate && isset($gate['id'])) {
            $slotGateDistance = SlotGateDistance::where('slot_id', $data['slot']['id'])
                ->where('gate_id', $gate['id'])
                ->first();

            if ($slotGateDistance) {
                $distanceFromGate = (float) $slotGateDistance->distance;
            }
        }

        // ✅ Thêm khoảng cách vào response
        if ($distanceFromGate !== null) {
            $data['distance_from_gate_meters'] = $distanceFromGate;
        }

        return response()->json([
            'success' => true,
            'data' => $data
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

        // ✅ Kiểm tra reservation đã có slot chưa
        if ($reservation->slot_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation chưa được gán slot. Vui lòng sử dụng endpoint demo/check-in với gate_id để cấp chỗ và check-in.'
            ], 422);
        }

        // Cập nhật reservation
        $reservation->update([
            'status' => 'checked_in',
            'check_in_at' => now()
        ]);

        // Slot chuyển sang occupied
        $reservation->slot->update(['status' => 'occupied']);

        // ✅ Reload reservation để lấy dữ liệu mới nhất
        $reservation->refresh();

        // ✅ Kiểm tra monthly pass sau khi check-in
        $monthlyPass = MonthlyPass::findValidPass(
            $reservation->user_id,
            $reservation->vehicle_id,
            $reservation->slot->parking_lot_id,
            $reservation->check_in_at ? $reservation->check_in_at->toDateString() : null
        );

        $hasMonthlyPass = $monthlyPass && $monthlyPass->isValid($reservation->check_in_at);

        $responseData = [
            'reservation' => $reservation,
        ];

        // ✅ Nếu có monthly pass, tạo checkout code ngay
        if ($hasMonthlyPass) {
            // Tạo Payment với amount = 0 (miễn phí)
            $payment = Payment::create([
                'order_id' => $reservation->reservation_code,
                'reservation_id' => $reservation->id,
                'amount' => 0,
                'txn_ref' => 'ORD' . now()->format('YmdHis') . rand(100, 999),
                'status' => 'PAID', // Tự động PAID vì có monthly pass
                'paid_at' => now(),
                'meta' => [
                    'payment_method' => 'monthly_pass',
                    'monthly_pass_id' => $monthlyPass->id,
                    'is_free' => true,
                ],
            ]);

            // Cập nhật reservation với payment_id
            $reservation->update(['payment_id' => $payment->id]);

            // Tạo checkout code
            $checkoutCode = $this->createCheckoutCode($reservation, $payment);

            // Thêm thông tin checkout code vào response
            $responseData['checkout_code'] = [
                'checkout_code' => $checkoutCode->checkout_code,
                'status' => $checkoutCode->status,
                'expires_at' => $checkoutCode->expires_at?->toIso8601String(),
                'qr_data' => $checkoutCode->checkout_code,
            ];
            $responseData['monthly_pass'] = [
                'id' => $monthlyPass->id,
                'order_id' => $monthlyPass->order_id,
                'end_date' => $monthlyPass->end_date?->toDateString(),
            ];
            $responseData['is_free'] = true;
            $responseData['skip_payment'] = true; // Flag để frontend biết bỏ qua bước thanh toán
        }

        return response()->json([
            'success' => true,
            'message' => $hasMonthlyPass
                ? 'Check-in thành công. Vé tháng của bạn đã được áp dụng. Vui lòng quét mã checkout khi ra khỏi bãi đỗ.'
                : 'Check-in thành công',
            'data' => $responseData
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

        if ($reservation->status !== 'checked_in' && $reservation->status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể check-out reservation đang checked_in hoặc pending_checkout. Trạng thái hiện tại: ' . $reservation->status
            ], 422);
        }

        $paymentMethod = $request->input('payment_method', 'online'); // Mặc định là online

        // ✅ Kiểm tra monthly pass trước khi tính phí
        $monthlyPass = MonthlyPass::findValidPass(
            $reservation->user_id,
            $reservation->vehicle_id,
            $reservation->slot->parking_lot_id,
            $reservation->check_in_at ? $reservation->check_in_at->toDateString() : null
        );

        $hasMonthlyPass = $monthlyPass && $monthlyPass->isValid($reservation->check_in_at);

        // ✅ Kiểm tra xem đã có payment chưa (từ check-in với monthly pass)
        $payment = $reservation->payment;

        if (!$payment) {
            // Chưa có payment, tạo mới
            // Tính toán giá tiền dựa trên pricing snapshot
            $amount = $this->calculatePaymentAmount($reservation);

            // Tạo Payment (nếu có monthly pass thì amount = 0, nhưng vẫn tạo payment record)
            $payment = Payment::create([
                'order_id' => $reservation->reservation_code,
                'reservation_id' => $reservation->id,
                'amount' => $amount,
                'txn_ref' => 'ORD' . now()->format('YmdHis') . rand(100, 999),
                'status' => $hasMonthlyPass ? 'PAID' : 'PENDING', // Nếu có monthly pass thì tự động PAID
                'meta' => [
                    'payment_method' => $paymentMethod,
                    'monthly_pass_id' => $hasMonthlyPass ? $monthlyPass->id : null,
                    'is_free' => $hasMonthlyPass,
                ],
            ]);
        } else {
            // Đã có payment, lấy amount từ payment hiện có
            $amount = $payment->amount;
        }

        // Xác định status dựa trên payment method và monthly pass
        if ($hasMonthlyPass) {
            // Có monthly pass → checked_out ngay (miễn phí, không cần thanh toán)
            $newStatus = 'checked_out';
            $reservation->slot->update(['status' => 'available']);
            $this->finalizeRequestIfAny($reservation);
        } elseif ($paymentMethod === 'offline') {
            // Thanh toán trực tiếp → pending_checkout (đã thanh toán trực tiếp, chờ nhân viên quét)
            $newStatus = 'pending_checkout';
            // Không giải phóng slot ngay, chờ nhân viên quét để finalize
        } else {
            // Thanh toán online → pending_payment (chưa thanh toán)
            $newStatus = 'pending_payment';
        }

        // Cập nhật reservation
        $reservation->update([
            'status' => $newStatus,
            'check_out_at' => now(),
            'payment_id' => $payment->id,
        ]);

        // ✅ Lấy checkout code nếu có
        $checkoutCode = CheckoutCode::where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $responseData = [
            'reservation' => $reservation,
            'payment' => $payment,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'monthly_pass' => $hasMonthlyPass ? [
                'id' => $monthlyPass->id,
                'order_id' => $monthlyPass->order_id,
                'end_date' => $monthlyPass->end_date?->toDateString(),
            ] : null,
            'is_free' => $hasMonthlyPass,
        ];

        // ✅ Nếu có checkout code, thêm vào response
        if ($checkoutCode) {
            $responseData['checkout_code'] = [
                'checkout_code' => $checkoutCode->checkout_code,
                'status' => $checkoutCode->status,
                'expires_at' => $checkoutCode->expires_at?->toIso8601String(),
                'qr_data' => $checkoutCode->checkout_code,
            ];
        }

        $message = $hasMonthlyPass
            ? 'Check-out thành công. Vé tháng của bạn đã được áp dụng (miễn phí).'
            : ($paymentMethod === 'offline'
                ? 'Check-out thành công (thanh toán trực tiếp)'
                : 'Check-out thành công. Vui lòng thanh toán để nhận mã QR checkout.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $responseData
        ]);
    }

    private function calculatePaymentAmount(Reservation $reservation): int
    {
        // ✅ Kiểm tra monthly pass trước - nếu có thì miễn phí
        $monthlyPass = MonthlyPass::findValidPass(
            $reservation->user_id,
            $reservation->vehicle_id,
            $reservation->slot->parking_lot_id,
            $reservation->check_in_at ? $reservation->check_in_at->toDateString() : null
        );

        if ($monthlyPass && $monthlyPass->isValid($reservation->check_in_at)) {
            // Có vé tháng hợp lệ → miễn phí
            return 0;
        }

        // Nếu không có monthly pass, tính phí bình thường
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
     *     path="/reservations/demo/check-in",
     *     tags={"🎫 Reservations"},
     *     summary="Demo check-in bằng reservation_code và gate_id",
     *     description="Check-in bằng reservation_code và gate_id. Lúc này mới chạy thuật toán cấp chỗ và gán slot cho reservation. Chỉ áp dụng khi reservation đang confirmed và chưa có slot.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reservation_code", "gate_id"},
     *             @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019"),
     *             @OA\Property(property="gate_id", type="integer", example=1, description="ID cổng vào")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Check-in thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Check-in thành công"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(
     *                     property="parking_lot",
     *                     type="object",
     *                     nullable=true,
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Tầng hầm B1"),
     *                     @OA\Property(property="gate_pos_x", type="number", nullable=true, example=10.806176400733),
     *                     @OA\Property(property="gate_pos_y", type="number", nullable=true, example=106.62866765108)
     *                 ),
     *                 @OA\Property(
     *                     property="allocated_slot",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="slot_code", type="string", example="MB-001"),
     *                     @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *                     @OA\Property(property="status", type="string", example="occupied"),
     *                     @OA\Property(property="position_x", type="number", example=10.806176400733),
     *                     @OA\Property(property="position_y", type="number", example=106.62866765108)
     *                 ),
     *                 @OA\Property(
     *                     property="gate",
     *                     type="object",
     *                     nullable=true,
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="gate_code", type="string", example="GATE-001"),
     *                     @OA\Property(property="gate_type", type="string", enum={"entrance", "exit", "both"}, example="entrance"),
     *                     @OA\Property(property="position_x", type="number", example=10.806176400733),
     *                     @OA\Property(property="position_y", type="number", example=106.62866765108),
     *                     @OA\Property(property="is_active", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(property="distance_from_gate_meters", type="number", nullable=true, example=25.5, description="Khoảng cách từ slot đến cổng (mét)"),
     *                 @OA\Property(property="algorithm_used", type="string", example="priority_queue"),
     *                 @OA\Property(property="processing_time_ms", type="number", example=302.5)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy reservation"),
     *     @OA\Response(response=422, description="Không thể check-in do không đúng trạng thái hoặc không có chỗ trống")
     * )
     */
    public function demoCheckIn(Request $request)
    {
        $validated = $request->validate([
            'reservation_code' => 'required|string',
            'gate_id' => 'required|integer|exists:gates,id'
        ]);

        // ✅ Trim và uppercase để đảm bảo format đúng
        $reservationCode = strtoupper(trim($validated['reservation_code']));
        $gateId = (int) $validated['gate_id'];

        $reservation = Reservation::where('reservation_code', $reservationCode)->first();

        if (!$reservation) {
            // ✅ Lấy danh sách 5 reservation codes gần nhất để user tham khảo
            $recentReservations = Reservation::latest()
                ->take(5)
                ->pluck('reservation_code', 'id')
                ->toArray();

            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy reservation với mã này',
                'debug' => [
                    'input' => $validated['reservation_code'],
                    'normalized' => $reservationCode,
                    'hint' => 'Format: RES-XXXXXXXX-YYYYMMDD (ví dụ: RES-AB12CD34-20251110)',
                    'recent_reservation_codes' => array_values($recentReservations),
                    'total_reservations' => Reservation::count()
                ]
            ], 404);
        }

        // ✅ Kiểm tra status trước khi check-in
        if ($reservation->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể check-in reservation đang confirmed. Trạng thái hiện tại: ' . $reservation->status
            ], 422);
        }

        // ✅ Kiểm tra reservation chưa có slot (chưa được gán chỗ)
        if ($reservation->slot_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation đã được gán slot. Không thể chạy lại thuật toán cấp chỗ.'
            ], 422);
        }

        return DB::transaction(function () use ($reservation, $gateId) {
            // 1. Lấy reservation request
            $reservationRequest = $reservation->reservationRequest;

            if (!$reservationRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy reservation request'
                ], 422);
            }

            // 2. Cập nhật reservation request với gate_id
            $reservationRequest->update([
                'gate_id' => $gateId
            ]);

            // 3. Chạy thuật toán cấp chỗ với gate_id
            $allocatedSlot = PriorityQueueSlotAllocationService::allocateSlot($reservationRequest);

            if (!$allocatedSlot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không có chỗ trống phù hợp với loại xe này tại cổng này'
                ], 422);
            }

            // 4. Final guard: kiểm tra chồng lấn lần cuối
            $start = Carbon::parse($reservation->start_time)->utc();
            $end = Carbon::parse($reservation->end_time)->utc();

            if (TimeOverlapService::hasOverlapOnSlot($allocatedSlot->id, $start, $end)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khung giờ đã bị trùng, vui lòng thử lại'
                ], 422);
            }

            // 5. Gán slot cho reservation
            $reservation->update([
                'slot_id' => $allocatedSlot->id
            ]);

            // 6. Cập nhật reservation request status → assigned
            $reservationRequest->update(['status' => 'assigned']);

            // 7. Check-in: cập nhật status và check_in_at
            $reservation->update([
                'status' => 'checked_in',
                'check_in_at' => now()
            ]);

            // 8. Slot chuyển sang occupied
            $allocatedSlot->update(['status' => 'occupied']);

            // ✅ Reload reservation để lấy dữ liệu mới nhất
            $reservation->refresh();
            $reservation->load(['slot.parkingLot', 'reservationRequest.gate']);

            // ✅ Lấy thông tin gate
            $gate = $reservationRequest->gate;

            // ✅ Lấy thông tin parking lot
            $parkingLot = $allocatedSlot->parkingLot;

            // ✅ Lấy khoảng cách từ slot đến cổng
            $distanceFromGate = null;
            if ($gate && $allocatedSlot) {
                $slotGateDistance = SlotGateDistance::where('slot_id', $allocatedSlot->id)
                    ->where('gate_id', $gate->id)
                    ->first();

                if ($slotGateDistance) {
                    $distanceFromGate = (float) $slotGateDistance->distance;
                }
            }

            // ✅ Kiểm tra monthly pass sau khi check-in
            $monthlyPass = MonthlyPass::findValidPass(
                $reservation->user_id,
                $reservation->vehicle_id,
                $allocatedSlot->parking_lot_id,
                $reservation->check_in_at ? $reservation->check_in_at->toDateString() : null
            );

            $hasMonthlyPass = $monthlyPass && $monthlyPass->isValid($reservation->check_in_at);

            // ✅ Chuẩn bị thông tin slot chi tiết
            $slotInfo = [
                'id' => $allocatedSlot->id,
                'slot_code' => $allocatedSlot->slot_code,
                'vehicle_type' => $allocatedSlot->vehicle_type,
                'status' => $allocatedSlot->status,
                'position_x' => $allocatedSlot->position_x,
                'position_y' => $allocatedSlot->position_y,
            ];

            // ✅ Chuẩn bị thông tin gate chi tiết
            $gateInfo = null;
            if ($gate) {
                $gateInfo = [
                    'id' => $gate->id,
                    'gate_code' => $gate->gate_code,
                    'gate_type' => $gate->gate_type,
                    'position_x' => $gate->position_x,
                    'position_y' => $gate->position_y,
                    'is_active' => $gate->is_active,
                ];
            }

            // ✅ Chuẩn bị thông tin parking lot chi tiết
            $parkingLotInfo = null;
            if ($parkingLot) {
                $parkingLotInfo = [
                    'id' => $parkingLot->id,
                    'name' => $parkingLot->name,
                    'gate_pos_x' => $parkingLot->gate_pos_x,
                    'gate_pos_y' => $parkingLot->gate_pos_y,
                ];
            }

            $responseData = [
                'reservation' => $reservation,
                'parking_lot' => $parkingLotInfo,
                'allocated_slot' => $slotInfo,
                'gate' => $gateInfo,
                'distance_from_gate_meters' => $distanceFromGate,
                'algorithm_used' => 'priority_queue',
                'processing_time_ms' => $reservationRequest->processing_time_ms,
            ];

            // ✅ Nếu có monthly pass, tạo checkout code ngay
            if ($hasMonthlyPass) {
                // Tạo Payment với amount = 0 (miễn phí)
                $payment = Payment::create([
                    'order_id' => $reservation->reservation_code,
                    'reservation_id' => $reservation->id,
                    'amount' => 0,
                    'txn_ref' => 'ORD' . now()->format('YmdHis') . rand(100, 999),
                    'status' => 'PAID', // Tự động PAID vì có monthly pass
                    'paid_at' => now(),
                    'meta' => [
                        'payment_method' => 'monthly_pass',
                        'monthly_pass_id' => $monthlyPass->id,
                        'is_free' => true,
                    ],
                ]);

                // Cập nhật reservation với payment_id
                $reservation->update(['payment_id' => $payment->id]);

                // Tạo checkout code
                $checkoutCode = $this->createCheckoutCode($reservation, $payment);

                // Thêm thông tin checkout code vào response
                $responseData['checkout_code'] = [
                    'checkout_code' => $checkoutCode->checkout_code,
                    'status' => $checkoutCode->status,
                    'expires_at' => $checkoutCode->expires_at?->toIso8601String(),
                    'qr_data' => $checkoutCode->checkout_code,
                ];
                $responseData['monthly_pass'] = [
                    'id' => $monthlyPass->id,
                    'order_id' => $monthlyPass->order_id,
                    'end_date' => $monthlyPass->end_date?->toDateString(),
                ];
                $responseData['is_free'] = true;
                $responseData['skip_payment'] = true; // Flag để frontend biết bỏ qua bước thanh toán
            }

            return response()->json([
                'success' => true,
                'message' => $hasMonthlyPass
                    ? 'Check-in thành công. Vé tháng của bạn đã được áp dụng. Vui lòng quét mã checkout khi ra khỏi bãi đỗ.'
                    : 'Check-in thành công',
                'data' => $responseData
            ]);
        });
    }

    /**
     * @OA\Post(
     *     path="/reservations/demo/check-out",
     *     tags={"🎫 Reservations"},
     *     summary="Demo check-out bằng reservation_code",
     *     description="Check-out bằng reservation_code. Xử lý 2 trường hợp: checked_in (tạo payment) và pending_checkout (finalize checkout). Đối với offline payment ở pending_checkout, hiển thị số tiền và chờ xác nhận.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reservation_code"},
     *             @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019", description="Mã reservation code"),
     *             @OA\Property(property="payment_method", type="string", enum={"online", "offline"}, example="online", description="Phương thức thanh toán: online (tạo pending_checkout) hoặc offline (checked_out ngay)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Check-out thành công hoặc yêu cầu xác nhận thanh toán",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đã quét QR thành công. Vui lòng xác nhận thanh toán để hoàn tất checkout."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(property="payment", type="object"),
     *                 @OA\Property(property="amount", type="integer", example=50000),
     *                 @OA\Property(property="amount_formatted", type="string", example="50.000 VNĐ"),
     *                 @OA\Property(property="payment_method", type="string", example="offline"),
     *                 @OA\Property(property="checked_out", type="boolean", example=false),
     *                 @OA\Property(property="requires_confirmation", type="boolean", example=true, description="Flag để frontend biết cần hiển thị nút xác nhận"),
     *                 @OA\Property(property="confirmation_endpoint", type="string", example="/reservations/demo/check-out/confirm")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy reservation"),
     *     @OA\Response(response=422, description="Không thể check-out do không đúng trạng thái")
     * )
     */
    public function demoCheckOut(Request $request)
    {
        $validated = $request->validate([
            'reservation_code' => 'required|string',
            'payment_method' => 'nullable|string|in:online,offline'
        ]);

        $reservation = Reservation::where('reservation_code', $validated['reservation_code'])->first();

        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy reservation với mã này'
            ], 404);
        }

        // ✅ pending_checkout → xử lý theo payment method
        if ($reservation->status === 'pending_checkout') {
            $payment = $reservation->payment;

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy thông tin thanh toán cho reservation này'
                ], 422);
            }

            $paymentMethod = is_array($payment->meta) ? ($payment->meta['payment_method'] ?? null) : null;

            // ✅ Nếu là offline payment và chưa PAID → hiển thị số tiền, chờ xác nhận
            if ($paymentMethod === 'offline' && $payment->status !== 'PAID') {
                // Lưu thông tin đã quét QR
                $existingMeta = is_array($payment->meta) ? $payment->meta : [];
                $payment->update([
                    'meta' => array_merge($existingMeta, [
                        'qr_scanned_at' => now()->toIso8601String(),
                        'qr_scanned' => true,
                    ]),
                ]);
                $payment->refresh();

                // Load thông tin đầy đủ
                $reservation->load(['slot.parkingLot', 'reservationRequest.gate']);

                return response()->json([
                    'success' => true,
                    'message' => 'Đã quét QR thành công. Vui lòng xác nhận thanh toán để hoàn tất checkout.',
                    'data' => [
                        'reservation' => [
                            'id' => $reservation->id,
                            'reservation_code' => $reservation->reservation_code,
                            'status' => $reservation->status,
                            'check_in_at' => $reservation->check_in_at?->toIso8601String(),
                            'check_out_at' => $reservation->check_out_at?->toIso8601String(),
                            'vehicle_snapshot' => $reservation->vehicle_snapshot,
                            'user_snapshot' => $reservation->user_snapshot,
                        ],
                        'payment' => [
                            'id' => $payment->id,
                            'order_id' => $payment->order_id,
                            'amount' => $payment->amount,
                            'status' => $payment->status,
                            'txn_ref' => $payment->txn_ref,
                            'created_at' => $payment->created_at?->toIso8601String(),
                        ],
                        'payment_method' => $paymentMethod,
                        'amount' => $payment->amount,
                        'amount_formatted' => number_format($payment->amount, 0, ',', '.') . ' VNĐ',
                        'payment_status' => $payment->status,
                        'checked_out' => false,
                        'requires_confirmation' => true,
                        'confirmation_endpoint' => '/reservations/demo/check-out/confirm',
                    ]
                ]);
            }

            // ✅ Online payment: Kiểm tra đã PAID chưa
            if ($paymentMethod === 'online' && $payment->status !== 'PAID') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment chưa được thanh toán thành công. Không thể finalize checkout.',
                    'payment_status' => $payment->status
                ], 422);
            }

            // ✅ Đã PAID → finalize checkout
            $reservation->update([
                'status' => 'checked_out',
            ]);

            if ($reservation->slot_id !== null && $reservation->slot) {
                $reservation->slot->update(['status' => 'available']);
            }

            $this->finalizeRequestIfAny($reservation);

            return response()->json([
                'success' => true,
                'message' => 'Checkout hoàn tất thành công',
                'data' => [
                    'reservation' => $reservation,
                    'payment' => $payment,
                    'checked_out' => true,
                ]
            ]);
        }

        // ✅ Nếu reservation đang ở checked_in → checkout bình thường
        if ($reservation->status === 'checked_in') {
            if (isset($validated['payment_method'])) {
                $request->merge(['payment_method' => $validated['payment_method']]);
            }
            return $this->checkOut($request, $reservation->id);
        }

        // Trạng thái khác không hợp lệ
        return response()->json([
            'success' => false,
            'message' => 'Reservation không ở trạng thái hợp lệ để checkout. Trạng thái hiện tại: ' . $reservation->status
        ], 422);
    }

    /**
     * @OA\Post(
     *     path="/reservations/demo/check-out/confirm",
     *     tags={"🎫 Reservations"},
     *     summary="Xác nhận thanh toán offline sau khi quét QR checkout",
     *     description="Nhân viên xác nhận đã nhận được tiền thanh toán offline. Chỉ áp dụng cho payment có payment_method = 'offline' và đã được quét QR.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reservation_code"},
     *             @OA\Property(property="reservation_code", type="string", example="RES-AB12CD34-20251019", description="Mã reservation code đã quét")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Xác nhận thanh toán thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Xác nhận thanh toán thành công. Checkout đã hoàn tất."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(property="payment", type="object"),
     *                 @OA\Property(property="checked_out", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy reservation hoặc payment"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể xác nhận thanh toán (đã thanh toán, không phải offline, chưa quét QR)"
     *     )
     *     )
     */
    public function confirmDemoCheckOut(Request $request)
    {
        $validated = $request->validate([
            'reservation_code' => 'required|string',
        ]);

        $reservation = Reservation::where('reservation_code', $validated['reservation_code'])->first();

        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy reservation với mã này'
            ], 404);
        }

        if ($reservation->status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Reservation không ở trạng thái pending_checkout. Trạng thái hiện tại: ' . $reservation->status
            ], 422);
        }

        $payment = $reservation->payment;
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy payment cho reservation này'
            ], 404);
        }

        $paymentMethod = is_array($payment->meta) ? ($payment->meta['payment_method'] ?? null) : null;

        if ($paymentMethod !== 'offline') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể xác nhận thanh toán offline. Payment này không phải offline payment.'
            ], 422);
        }

        if ($payment->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Payment không ở trạng thái PENDING. Trạng thái hiện tại: ' . $payment->status
            ], 422);
        }

        // Kiểm tra đã quét QR chưa
        $qrScanned = isset($payment->meta['qr_scanned']) && $payment->meta['qr_scanned'] === true;
        if (!$qrScanned) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa quét QR checkout code. Vui lòng quét QR trước khi xác nhận.'
            ], 422);
        }

        // Xác nhận thanh toán
        $existingMeta = is_array($payment->meta) ? $payment->meta : [];
        $payment->update([
            'status' => 'PAID',
            'paid_at' => now(),
            'meta' => array_merge($existingMeta, [
                'confirmed_at' => now()->toIso8601String(),
                'confirmed_by' => 'staff',
            ]),
        ]);

        // Finalize checkout
        $reservation->update([
            'status' => 'checked_out',
        ]);

        if ($reservation->slot_id !== null && $reservation->slot) {
            $reservation->slot->update(['status' => 'available']);
        }

        $this->finalizeRequestIfAny($reservation);

        return response()->json([
            'success' => true,
            'message' => 'Xác nhận thanh toán thành công. Checkout đã hoàn tất.',
            'data' => [
                'reservation' => $reservation,
                'payment' => $payment,
                'checked_out' => true,
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

            // Finalize request: khi reservation expired, request đã hoàn thành vai trò assign slot
            // Nếu request status = 'assigned' → set 'completed' (đã hoàn thành)
            // Nếu request status = 'pending' → set 'failed' (không còn chỗ)
            if ($reservation->reservation_request_id) {
                $request = ReservationRequest::find($reservation->reservation_request_id);
                if ($request) {
                    if ($request->status === 'assigned') {
                        $request->update(['status' => 'completed']);
                    } elseif ($request->status === 'pending') {
                        $request->update(['status' => 'failed']);
                    }
                }
            }

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
        $query = Reservation::with(['slot.parkingLot', 'reservationRequest.gate', 'reservationRequest.parkingLot'])
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

        // Lấy thông tin payment cho mỗi reservation và transform để đưa gate ra cùng cấp
        $transformedReservations = $reservations->getCollection()->map(function ($reservation) {
            // Tìm payment theo order_id (có thể là reservation_code) hoặc trong meta
            $payment = Payment::where('order_id', $reservation->reservation_code)
                ->orWhere(function ($q) use ($reservation) {
                    $q->whereJsonContains('meta->reservation_id', $reservation->id);
                })
                ->orderByDesc('created_at')
                ->first();

            // Thêm payment vào reservation
            $reservation->payment = $payment;

            // Transform để đưa gate và parking_lot ra cùng cấp với reservation
            $data = $reservation->toArray();

            // Tách gate ra khỏi reservation_request và đặt ở cùng cấp
            $gate = null;
            if (isset($data['reservation_request']['gate'])) {
                $gate = $data['reservation_request']['gate'];
                $data['gate'] = $gate;
                unset($data['reservation_request']['gate']);
            }

            // ✅ Lấy thông tin parking lot: từ slot nếu có, nếu không thì từ reservation_request
            $parkingLot = null;
            if (isset($data['slot']['parking_lot']) && $data['slot']['parking_lot']) {
                $parkingLot = $data['slot']['parking_lot'];
            } elseif (isset($data['reservation_request']['parking_lot']) && $data['reservation_request']['parking_lot']) {
                $parkingLot = $data['reservation_request']['parking_lot'];
            }

            // ✅ Đưa parking_lot ra cùng cấp với reservation
            if ($parkingLot) {
                $data['parking_lot'] = $parkingLot;
            }

            // ✅ Lấy khoảng cách từ slot đến cổng (nếu có slot và gate)
            $distanceFromGate = null;
            if (isset($data['slot']['id']) && $gate && isset($gate['id'])) {
                $slotGateDistance = SlotGateDistance::where('slot_id', $data['slot']['id'])
                    ->where('gate_id', $gate['id'])
                    ->first();

                if ($slotGateDistance) {
                    $distanceFromGate = (float) $slotGateDistance->distance;
                }
            }

            // ✅ Thêm khoảng cách vào response
            if ($distanceFromGate !== null) {
                $data['distance_from_gate_meters'] = $distanceFromGate;
            }

            return $data;
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
                'reservations' => $transformedReservations->values()->all(),
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
     * Kiểm tra số slot available cho loại xe trong khung giờ cụ thể
     * Bao gồm cả các reservation có slot_id = null (giữ chỗ không cụ thể)
     * 
     * @param int $parkingLotId
     * @param string $vehicleType
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return int Số slot còn trống
     */
    private function checkAvailableSlotsForReservation(int $parkingLotId, string $vehicleType, Carbon $startTime, Carbon $endTime): int
    {
        // 1. Tổng số slot cho loại xe này
        $totalSlots = \App\Models\ParkingSlot::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->count();

        // 2. Đếm số slot đã được gán cụ thể (có slot_id) và overlap thời gian
        $assignedSlotIds = Reservation::whereHas('slot', function ($q) use ($parkingLotId, $vehicleType) {
            $q->where('parking_lot_id', $parkingLotId)
                ->where('vehicle_type', $vehicleType);
        })
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($startTime, $endTime) {
                // Overlap: existing.end > new.start AND existing.start < new.end
                $q->where('end_time', '>', $startTime)
                    ->where('start_time', '<', $endTime);
            })
            ->whereNotNull('slot_id')
            ->pluck('slot_id')
            ->unique()
            ->count();

        // 3. Đếm số reservation đang giữ chỗ không cụ thể (slot_id = null) và overlap thời gian
        // Mỗi reservation này "giữ" 1 slot (không cụ thể)
        $heldSlotsCount = Reservation::whereNull('slot_id')
            ->whereIn('status', ['confirmed'])
            ->whereHas('reservationRequest', function ($q) use ($parkingLotId, $vehicleType) {
                $q->where('parking_lot_id', $parkingLotId)
                    ->where('vehicle_type', $vehicleType);
            })
            ->where(function ($q) use ($startTime, $endTime) {
                // Overlap: existing.end > new.start AND existing.start < new.end
                $q->where('end_time', '>', $startTime)
                    ->where('start_time', '<', $endTime);
            })
            ->where(function ($q) {
                // Chỉ tính các reservation chưa hết hạn giữ chỗ (expires_at >= now hoặc null)
                $q->where('expires_at', '>=', now())
                    ->orWhereNull('expires_at');
            })
            ->count();

        // 4. Tính số slot còn trống
        $availableSlots = $totalSlots - $assignedSlotIds - $heldSlotsCount;

        return max(0, $availableSlots);
    }

    /**
     * Tạo QR checkout code sau khi thanh toán thành công hoặc có monthly pass
     */
    private function createCheckoutCode(Reservation $reservation, Payment $payment): CheckoutCode
    {
        // Tạo mã checkout code duy nhất
        $checkoutCode = 'CHK-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');

        // Kiểm tra mã đã tồn tại chưa (rất hiếm nhưng để an toàn)
        while (CheckoutCode::where('checkout_code', $checkoutCode)->exists()) {
            $checkoutCode = 'CHK-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');
        }

        // Tạo checkout code với thời gian hết hạn 24 giờ
        $checkoutCodeModel = CheckoutCode::create([
            'reservation_id' => $reservation->id,
            'payment_id' => $payment->id,
            'checkout_code' => $checkoutCode,
            'status' => 'active',
            'expires_at' => now()->addHours(24),
        ]);

        return $checkoutCodeModel;
    }

    /**
     * @OA\Post(
     *     path="/reservations/scan-checkout",
     *     tags={"🎫 Reservations"},
     *     summary="Quét QR checkout code để hoàn tất checkout",
     *     description="Quét QR checkout code để chuyển reservation từ pending_checkout sang checked_out. Đối với thanh toán online: tự động checked_out. Đối với thanh toán offline: hiển thị số tiền và chờ nhân viên xác nhận.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"checkout_code"},
     *             @OA\Property(property="checkout_code", type="string", example="CHK-ABC12345-20251110")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Quét QR thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Đã quét QR thành công. Vui lòng xác nhận thanh toán để hoàn tất checkout."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(property="checkout_code", type="object"),
     *                 @OA\Property(property="payment", type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="amount", type="integer", example=50000),
     *                     @OA\Property(property="status", type="string", example="PENDING")
     *                 ),
     *                 @OA\Property(property="payment_method", type="string", example="offline"),
     *                 @OA\Property(property="amount", type="integer", example=50000),
     *                 @OA\Property(property="amount_formatted", type="string", example="50.000 VNĐ"),
     *                 @OA\Property(property="checked_out", type="boolean", example=false),
     *                 @OA\Property(property="requires_confirmation", type="boolean", example=true, description="Flag để frontend biết cần hiển thị nút xác nhận"),
     *                 @OA\Property(property="confirmation_endpoint", type="string", example="/payments/123/confirm-offline")
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

        // ✅ Kiểm tra payment method để xác định online hay offline
        $payment = $reservation->payment;
        $paymentMethod = null;

        if ($payment && is_array($payment->meta)) {
            $paymentMethod = $payment->meta['payment_method'] ?? null;
        }

        // ✅ Xử lý theo payment method
        if ($paymentMethod === 'online') {
            // Online payment: Kiểm tra payment đã PAID chưa
            if ($payment && $payment->status !== 'PAID') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment chưa được thanh toán thành công. Không thể finalize checkout.',
                    'payment_status' => $payment->status
                ], 422);
            }

            // Finalize checkout ngay cho online payment
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
                'message' => 'Checkout thành công (thanh toán online)',
                'data' => [
                    'reservation' => $reservation,
                    'checkout_code' => $checkoutCode,
                    'payment' => $payment,
                    'payment_method' => $paymentMethod,
                    'checked_out' => true,
                ]
            ]);
        } elseif ($paymentMethod === 'offline') {
            // Offline payment: Hiện số tiền và chờ nhân viên xác nhận
            // Không checked_out ngay, chỉ trả về thông tin payment
            // Lưu thông tin đã quét QR vào payment meta để biết đã quét
            if ($payment) {
                $existingMeta = is_array($payment->meta) ? $payment->meta : [];
                $payment->update([
                    'meta' => array_merge($existingMeta, [
                        'qr_scanned_at' => now()->toIso8601String(),
                        'qr_scanned' => true,
                        'checkout_code_scanned' => $checkoutCode->checkout_code,
                    ]),
                ]);
                $payment->refresh();
            }

            // Kiểm tra payment status
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy thông tin thanh toán cho reservation này'
                ], 422);
            }

            if ($payment->status === 'PAID') {
                return response()->json([
                    'success' => false,
                    'message' => 'Thanh toán đã được xác nhận trước đó',
                    'payment_status' => $payment->status
                ], 422);
            }

            // Load thông tin đầy đủ về reservation và vehicle
            $reservation->load(['slot.parkingLot', 'reservationRequest.gate']);

            return response()->json([
                'success' => true,
                'message' => 'Đã quét QR thành công. Vui lòng xác nhận thanh toán để hoàn tất checkout.',
                'data' => [
                    'reservation' => [
                        'id' => $reservation->id,
                        'reservation_code' => $reservation->reservation_code,
                        'status' => $reservation->status,
                        'check_in_at' => $reservation->check_in_at?->toIso8601String(),
                        'check_out_at' => $reservation->check_out_at?->toIso8601String(),
                        'vehicle_snapshot' => $reservation->vehicle_snapshot,
                        'user_snapshot' => $reservation->user_snapshot,
                    ],
                    'checkout_code' => [
                        'checkout_code' => $checkoutCode->checkout_code,
                        'status' => $checkoutCode->status,
                        'expires_at' => $checkoutCode->expires_at?->toIso8601String(),
                    ],
                    'payment' => [
                        'id' => $payment->id,
                        'order_id' => $payment->order_id,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'txn_ref' => $payment->txn_ref,
                        'created_at' => $payment->created_at?->toIso8601String(),
                    ],
                    'payment_method' => $paymentMethod,
                    'amount' => $payment->amount,
                    'amount_formatted' => number_format($payment->amount, 0, ',', '.') . ' VNĐ',
                    'payment_status' => $payment->status,
                    'checked_out' => false,
                    'requires_confirmation' => true, // Flag để frontend biết cần hiển thị nút xác nhận
                    'confirmation_endpoint' => "/payments/{$payment->id}/confirm-offline", // Endpoint để xác nhận
                ]
            ]);
        } else {
            // Payment method không xác định
            return response()->json([
                'success' => false,
                'message' => 'Không xác định được phương thức thanh toán',
                'payment_method' => $paymentMethod
            ], 422);
        }
    }

    /**
     * @OA\Post(
     *     path="/reservations/scan-checkout/confirm",
     *     tags={"🎫 Reservations"},
     *     summary="Xác nhận thanh toán offline sau khi quét QR checkout",
     *     description="Nhân viên xác nhận đã nhận được tiền thanh toán offline. Chỉ áp dụng cho payment có payment_method = 'offline' và đã được quét QR.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"checkout_code"},
     *             @OA\Property(property="checkout_code", type="string", example="CHK-ABC12345-20251110", description="Mã checkout code đã quét")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Xác nhận thanh toán thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Xác nhận thanh toán thành công. Checkout đã hoàn tất."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reservation", type="object"),
     *                 @OA\Property(property="payment", type="object"),
     *                 @OA\Property(property="checked_out", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy checkout code hoặc payment"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Không thể xác nhận thanh toán (đã thanh toán, không phải offline, chưa quét QR)"
     *     )
     *     )
     */
    public function confirmOfflinePaymentFromCheckout(Request $request)
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

        $reservation = $checkoutCode->reservation;
        $payment = $reservation->payment;

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thông tin thanh toán cho reservation này'
            ], 404);
        }

        // Kiểm tra payment method phải là offline
        $paymentMethod = is_array($payment->meta) ? ($payment->meta['payment_method'] ?? null) : null;
        if ($paymentMethod !== 'offline') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể xác nhận thanh toán trực tiếp (offline). Payment này không phải offline payment.'
            ], 422);
        }

        // Kiểm tra payment đã được quét QR chưa
        $qrScanned = isset($payment->meta['qr_scanned']) && $payment->meta['qr_scanned'] === true;
        if (!$qrScanned) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa quét QR checkout code. Vui lòng quét QR trước khi xác nhận thanh toán.'
            ], 422);
        }

        // Kiểm tra payment status phải là PENDING
        if ($payment->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể xác nhận thanh toán với status PENDING. Payment hiện tại có status: ' . $payment->status
            ], 422);
        }

        // Kiểm tra reservation status phải là pending_checkout
        if ($reservation->status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Reservation không ở trạng thái pending_checkout. Trạng thái hiện tại: ' . $reservation->status
            ], 422);
        }

        // Xác nhận thanh toán: cập nhật payment status thành PAID
        $payment->update([
            'status' => 'PAID',
            'paid_at' => now(),
        ]);

        // Cập nhật meta với thông tin xác nhận
        $existingMeta = is_array($payment->meta) ? $payment->meta : [];
        $payment->update([
            'meta' => array_merge($existingMeta, [
                'confirmed_at' => now()->toIso8601String(),
                'confirmed_by' => 'staff', // Có thể lấy từ auth user sau này
            ]),
        ]);

        // Finalize checkout: chuyển reservation sang checked_out
        $reservation->update([
            'status' => 'checked_out',
        ]);

        // Giải phóng slot
        if ($reservation->slot_id !== null && $reservation->slot) {
            $reservation->slot->update(['status' => 'available']);
        }

        // Finalize request
        $this->finalizeRequestIfAny($reservation);

        // Đánh dấu checkout code đã sử dụng
        $checkoutCode->markAsUsed();

        // Reload để lấy dữ liệu mới nhất
        $reservation->refresh();
        $payment->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Xác nhận thanh toán thành công. Checkout đã hoàn tất.',
            'data' => [
                'reservation' => $reservation,
                'payment' => $payment,
                'checkout_code' => $checkoutCode,
                'checked_out' => true,
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
