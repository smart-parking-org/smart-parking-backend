<?php

namespace App\Http\Controllers;

use App\Models\Violation;
use App\Models\Reservation;
use App\Models\Payment;
use App\Services\AuthService;
use App\Services\VnpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="🚨 Violations",
 *     description="Quản lý vi phạm đỗ xe"
 * )
 */
class ViolationController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    /**
     * Lazy load VnpayService chỉ khi cần dùng (tránh lỗi khi config VNPay chưa có)
     */
    private function getVnpayService(): VnpayService
    {
        return app(VnpayService::class);
    }

    /**
     * @OA\Get(
     *     path="/violations",
     *     tags={"🚨 Violations"},
     *     summary="Danh sách vi phạm",
     *     @OA\Parameter(name="user_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="vehicle_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="parking_lot_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"OVERSTAY", "LATE_CHECK_IN", "PARKING_EXPIRED_CHECK_IN", "NO_SHOW", "LATE_PAYMENT", "WRONG_SLOT", "NO_RESERVATION", "OTHER"})),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"PENDING", "RESOLVED", "CANCELLED", "APPEALED"})),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", example=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách vi phạm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Violation")),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $r)
    {
        $query = Violation::query();

        if ($r->has('user_id')) {
            $query->where('user_id', $r->user_id);
        }

        if ($r->has('vehicle_id')) {
            $query->where('vehicle_id', $r->vehicle_id);
        }

        if ($r->has('parking_lot_id')) {
            $query->where('parking_lot_id', $r->parking_lot_id);
        }

        if ($r->has('type')) {
            $query->where('type', $r->type);
        }

        if ($r->has('status')) {
            $query->where('status', $r->status);
        }

        $perPage = $r->get('per_page', 20);
        $violations = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($violations);
    }

    /**
     * @OA\Post(
     *     path="/violations",
     *     tags={"🚨 Violations"},
     *     summary="Tạo vi phạm mới (Thủ công - cho WRONG_SLOT, NO_RESERVATION, OTHER)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"type", "severity"},
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="vehicle_id", type="integer", example=10),
     *             @OA\Property(property="reservation_id", type="integer", nullable=true, example=5),
     *             @OA\Property(property="parking_lot_id", type="integer", nullable=true, example=2),
     *             @OA\Property(property="slot_id", type="integer", nullable=true, example=15),
     *             @OA\Property(property="type", type="string", enum={"WRONG_SLOT", "NO_RESERVATION", "OTHER"}, example="WRONG_SLOT"),
     *             @OA\Property(property="severity", type="string", enum={"LOW", "MEDIUM", "HIGH", "CRITICAL"}, example="MEDIUM"),
     *             @OA\Property(property="description", type="string", example="Đỗ sai chỗ: được phân slot A-01 nhưng đỗ tại slot B-05"),
     *             @OA\Property(property="fine_amount", type="integer", example=50000),
     *             @OA\Property(property="evidence_url", type="string", nullable=true),
     *             @OA\Property(property="violation_time", type="string", format="date-time", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tạo vi phạm thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/Violation")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'user_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
            'reservation_id' => 'nullable|integer|exists:reservations,id',
            'parking_lot_id' => 'nullable|integer|exists:parking_lots,id',
            'slot_id' => 'nullable|integer|exists:parking_slots,id',
            'type' => 'required|in:WRONG_SLOT,NO_RESERVATION,OTHER',
            'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL',
            'description' => 'nullable|string',
            'fine_amount' => 'nullable|integer|min:0',
            'evidence_url' => 'nullable|string|url',
            'violation_time' => 'nullable|date',
        ]);

        // Lấy snapshot nếu có user_id hoặc vehicle_id
        if (!empty($data['user_id'])) {
            $user = $this->authService->getUserSnapshot($data['user_id']);
            $data['user_snapshot'] = $user;
        }

        if (!empty($data['vehicle_id'])) {
            $vehicle = $this->authService->getVehicleSnapshot($data['vehicle_id']);
            $data['vehicle_snapshot'] = $vehicle;
        }

        // Lấy reservation snapshot nếu có reservation_id
        if (!empty($data['reservation_id'])) {
            $reservation = Reservation::find($data['reservation_id']);
            if ($reservation) {
                $data['reservation_snapshot'] = [
                    'reservation_code' => $reservation->reservation_code,
                    'status' => $reservation->status,
                    'start_time' => $reservation->start_time?->toIso8601String(),
                    'end_time' => $reservation->end_time?->toIso8601String(),
                    'check_in_at' => $reservation->check_in_at?->toIso8601String(),
                    'check_out_at' => $reservation->check_out_at?->toIso8601String(),
                    'allocated_slot_id' => $reservation->slot_id,
                    'allocated_slot_code' => $reservation->slot?->slot_code,
                ];
                
                // Tự động lấy parking_lot_id và slot_id nếu chưa có
                if (empty($data['parking_lot_id'])) {
                    $data['parking_lot_id'] = $reservation->slot->parking_lot_id ?? null;
                }
            }
        }

        // Tạo ticket_number tự động
        $data['ticket_number'] = 'VP-' . now()->format('Ymd') . '-' . str_pad(rand(1, 9999), 5, '0', STR_PAD_LEFT);
        $data['violation_time'] = $data['violation_time'] ?? now();

        $violation = Violation::create($data);

        return response()->json(['data' => $violation], 201);
    }

    /**
     * @OA\Get(
     *     path="/violations/{id}",
     *     tags={"🚨 Violations"},
     *     summary="Chi tiết vi phạm",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chi tiết vi phạm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/Violation")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vi phạm")
     * )
     */
    public function show($id)
    {
        $violation = Violation::find($id);
        if (!$violation) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $violation]);
    }

    /**
     * @OA\Get(
     *     path="/violations/mine",
     *     tags={"🚨 Violations"},
     *     summary="Danh sách vi phạm của user",
     *     @OA\Parameter(name="user_id", in="query", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"PENDING", "RESOLVED", "CANCELLED", "APPEALED"})),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách vi phạm của user",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Violation"))
     *         )
     *     )
     * )
     */
    public function mine(Request $r)
    {
        $r->validate([
            'user_id' => 'required|integer',
        ]);

        $query = Violation::where('user_id', $r->user_id);

        if ($r->has('status')) {
            $query->where('status', $r->status);
        }

        $violations = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => $violations]);
    }

    /**
     * @OA\Get(
     *     path="/users/{user_id}/violations",
     *     tags={"🚨 Violations"},
     *     summary="Lịch sử vi phạm của cư dân",
     *     description="Lấy danh sách vi phạm của một cư dân cụ thể - phục vụ quản lý cư dân (Yêu cầu đề tài: Quản lý cư dân - lịch sử vi phạm)",
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"PENDING", "RESOLVED", "CANCELLED", "APPEALED"})),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", example=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Lịch sử vi phạm của cư dân",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Violation")),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="last_page", type="integer")
     *             ),
     *             @OA\Property(property="summary", type="object",
     *                 @OA\Property(property="total_violations", type="integer", description="Tổng số vi phạm"),
     *                 @OA\Property(property="pending_count", type="integer", description="Số vi phạm chờ xử lý"),
     *                 @OA\Property(property="resolved_count", type="integer", description="Số vi phạm đã xử lý"),
     *                 @OA\Property(property="total_fines", type="integer", description="Tổng tiền phạt (VND)")
     *             )
     *         )
     *     )
     * )
     */
    public function getUserViolations(Request $r, $userId)
    {
        $query = Violation::where('user_id', $userId);

        if ($r->has('status')) {
            $query->where('status', $r->status);
        }

        $perPage = $r->get('per_page', 20);
        $violations = $query->orderByDesc('created_at')->paginate($perPage);

        // Thống kê tổng hợp
        $summary = [
            'total_violations' => Violation::where('user_id', $userId)->count(),
            'pending_count' => Violation::where('user_id', $userId)->where('status', 'PENDING')->count(),
            'resolved_count' => Violation::where('user_id', $userId)->where('status', 'RESOLVED')->count(),
            'total_fines' => (int) Violation::where('user_id', $userId)
                ->whereNotNull('fine_amount')
                ->sum('fine_amount'),
        ];

        return response()->json([
            'data' => $violations->items(),
            'meta' => [
                'current_page' => $violations->currentPage(),
                'per_page' => $violations->perPage(),
                'total' => $violations->total(),
                'last_page' => $violations->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * @OA\Put(
     *     path="/violations/{id}/resolve",
     *     tags={"🚨 Violations"},
     *     summary="Xử lý vi phạm (Admin)",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="resolved_by", type="integer", example=1, description="Admin ID"),
     *             @OA\Property(property="resolution_note", type="string", example="Đã xử lý, người dùng đã thanh toán"),
     *             @OA\Property(property="fine_amount", type="integer", nullable=true, example=50000, description="Cập nhật tiền phạt nếu cần")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Xử lý thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/Violation")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vi phạm")
     * )
     */
    public function resolve(Request $r, $id)
    {
        $violation = Violation::find($id);
        if (!$violation) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $r->validate([
            'resolved_by' => 'required|integer',
            'resolution_note' => 'nullable|string',
            'fine_amount' => 'nullable|integer|min:0',
        ]);

        $violation->update([
            'status' => 'RESOLVED',
            'resolved_by' => $data['resolved_by'],
            'resolved_at' => now(),
            'resolution_note' => $data['resolution_note'] ?? null,
            'fine_amount' => $data['fine_amount'] ?? $violation->fine_amount,
        ]);

        return response()->json(['data' => $violation]);
    }

    /**
     * @OA\Put(
     *     path="/violations/{id}/cancel",
     *     tags={"🚨 Violations"},
     *     summary="Hủy vi phạm",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="resolution_note", type="string", example="Hủy do nhầm lẫn")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Hủy thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/Violation")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vi phạm")
     * )
     */
    public function cancel(Request $r, $id)
    {
        $violation = Violation::find($id);
        if (!$violation) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $violation->update([
            'status' => 'CANCELLED',
            'resolution_note' => $r->resolution_note ?? null,
        ]);

        return response()->json(['data' => $violation]);
    }

    /**
     * @OA\Post(
     *     path="/violations/{id}/create-payment",
     *     tags={"🚨 Violations"},
     *     summary="Tạo thanh toán phạt cho vi phạm",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="bank_code", type="string", nullable=true, example="NCB")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tạo thanh toán thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="order_id", type="string", example="VP-PAY-20251030-12345"),
     *             @OA\Property(property="txn_ref", type="string", example="ORD20251030120000123"),
     *             @OA\Property(property="amount", type="integer", example=50000),
     *             @OA\Property(property="payUrl", type="string", example="https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?...")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vi phạm"),
     *     @OA\Response(response=422, description="Vi phạm không có tiền phạt hoặc đã có payment")
     * )
     */
    public function createPayment(Request $r, $id)
    {
        $violation = Violation::find($id);
        if (!$violation) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$violation->fine_amount || $violation->fine_amount <= 0) {
            return response()->json(['message' => 'Vi phạm không có tiền phạt'], 422);
        }

        if ($violation->payment_id) {
            return response()->json(['message' => 'Vi phạm đã có thanh toán'], 422);
        }

        $orderId = 'VP-PAY-' . now()->format('YmdHis') . '-' . rand(10000, 99999);
        $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);

        // Tạo Payment record
        $payment = Payment::create([
            'order_id' => $orderId,
            'amount' => $violation->fine_amount,
            'txn_ref' => $txnRef,
            'status' => 'PENDING',
            'meta' => [
                'type' => 'violation_fine',
                'violation_id' => $violation->id,
                'user_id' => $violation->user_id,
                'vehicle_id' => $violation->vehicle_id,
                'ticket_number' => $violation->ticket_number,
            ],
        ]);

        // Cập nhật violation với payment_id
        $violation->update(['payment_id' => $payment->id]);

        // Sinh URL thanh toán VNPay
        $payUrl = $this->getVnpayService()->createPaymentUrl([
            'order_id' => $orderId,
            'amount' => $violation->fine_amount,
            'txn_ref' => $txnRef,
            'bank_code' => $r->bank_code ?? null,
        ]);

        return response()->json([
            'id' => $payment->id,
            'order_id' => $orderId,
            'txn_ref' => $txnRef,
            'amount' => $violation->fine_amount,
            'payUrl' => $payUrl,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/violations/detect-overstay",
     *     tags={"🚨 Violations"},
     *     summary="Tự động phát hiện vi phạm đỗ quá giờ",
     *     description="Quét tất cả reservations đã check-out và phát hiện vi phạm đỗ quá giờ",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="from_date", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="to_date", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="auto_create", type="boolean", default=false, description="Tự động tạo violation nếu phát hiện")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách reservations vi phạm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="detected", type="integer", example=5),
     *             @OA\Property(property="violations_created", type="integer", example=5),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Violation"))
     *         )
     *     )
     * )
     */
    public function detectOverstay(Request $r)
    {
        $query = Reservation::with('slot')
            ->where('status', 'checked_out')
            ->whereNotNull('check_out_at')
            ->whereNotNull('end_time')
            ->whereColumn('check_out_at', '>', 'end_time');

        if ($r->has('from_date')) {
            $query->where('check_out_at', '>=', $r->from_date);
        }

        if ($r->has('to_date')) {
            $query->where('check_out_at', '<=', $r->to_date);
        }

        $violations = [];
        $reservations = $query->get();

        foreach ($reservations as $reservation) {
            // Kiểm tra đã có violation chưa
            $existing = Violation::where('reservation_id', $reservation->id)
                ->where('type', 'OVERSTAY')
                ->first();

            if ($existing) {
                continue;
            }

            $overstayMinutes = $reservation->check_out_at->diffInMinutes($reservation->end_time);
            $fineAmount = $this->calculateOverstayFine($overstayMinutes, $reservation);

            if ($r->get('auto_create', false)) {
                $slot = $reservation->slot;
                if (!$slot) {
                    continue; // Bỏ qua nếu không có slot
                }

                $violation = Violation::create([
                    'user_id' => $reservation->user_id,
                    'vehicle_id' => $reservation->vehicle_id,
                    'reservation_id' => $reservation->id,
                    'parking_lot_id' => $slot->parking_lot_id,
                    'slot_id' => $reservation->slot_id,
                    'type' => 'OVERSTAY',
                    'severity' => $this->determineSeverity($overstayMinutes),
                    'status' => 'PENDING',
                    'description' => "Đỗ xe quá giờ {$overstayMinutes} phút so với thời gian đặt ({$reservation->end_time->format('H:i')} - {$reservation->check_out_at->format('H:i')})",
                    'fine_amount' => $fineAmount,
                    'violation_time' => $reservation->check_out_at,
                    'ticket_number' => 'VP-' . now()->format('Ymd') . '-' . str_pad(rand(1, 9999), 5, '0', STR_PAD_LEFT),
                    'user_snapshot' => $reservation->user_snapshot,
                    'vehicle_snapshot' => $reservation->vehicle_snapshot,
                    'reservation_snapshot' => [
                        'reservation_code' => $reservation->reservation_code,
                        'start_time' => $reservation->start_time?->toIso8601String(),
                        'end_time' => $reservation->end_time?->toIso8601String(),
                        'check_out_at' => $reservation->check_out_at?->toIso8601String(),
                    ],
                ]);
                $violations[] = $violation;
            } else {
                // Chỉ trả về thông tin, không tạo
                $violations[] = [
                    'reservation_id' => $reservation->id,
                    'reservation_code' => $reservation->reservation_code,
                    'overstay_minutes' => $overstayMinutes,
                    'estimated_fine' => $fineAmount,
                ];
            }
        }

        return response()->json([
            'detected' => count($violations),
            'violations_created' => $r->get('auto_create', false) ? count($violations) : 0,
            'data' => $violations,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/violations/detect-late-checkin",
     *     tags={"🚨 Violations"},
     *     summary="Tự động phát hiện check-in muộn",
     *     description="Phát hiện reservations check-in muộn so với start_time",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="late_threshold_minutes", type="integer", default=15, description="Số phút muộn để tính vi phạm"),
     *             @OA\Property(property="auto_create", type="boolean", default=false)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Danh sách vi phạm")
     * )
     */
    public function detectLateCheckIn(Request $r)
    {
        $threshold = $r->get('late_threshold_minutes', 15);
        
        $query = Reservation::with('slot')
            ->where('status', 'checked_in')
            ->whereNotNull('check_in_at')
            ->whereNotNull('start_time')
            ->whereRaw("TIMESTAMPDIFF(MINUTE, start_time, check_in_at) > ?", [$threshold]);

        $violations = [];
        $reservations = $query->get();

        foreach ($reservations as $reservation) {
            $lateMinutes = $reservation->check_in_at->diffInMinutes($reservation->start_time);

            // Kiểm tra đã có violation chưa
            $existing = Violation::where('reservation_id', $reservation->id)
                ->where('type', 'LATE_CHECK_IN')
                ->first();

            if ($existing) {
                continue;
            }

            if ($r->get('auto_create', false)) {
                $slot = $reservation->slot;
                if (!$slot) {
                    continue; // Bỏ qua nếu không có slot
                }

                $violation = Violation::create([
                    'user_id' => $reservation->user_id,
                    'vehicle_id' => $reservation->vehicle_id,
                    'reservation_id' => $reservation->id,
                    'parking_lot_id' => $slot->parking_lot_id,
                    'slot_id' => $reservation->slot_id,
                    'type' => 'LATE_CHECK_IN',
                    'severity' => $this->determineSeverity($lateMinutes),
                    'status' => 'PENDING',
                    'description' => "Check-in muộn {$lateMinutes} phút so với thời gian đặt ({$reservation->start_time->format('H:i')} - {$reservation->check_in_at->format('H:i')})",
                    'violation_time' => $reservation->check_in_at,
                    'ticket_number' => 'VP-' . now()->format('Ymd') . '-' . str_pad(rand(1, 9999), 5, '0', STR_PAD_LEFT),
                    'user_snapshot' => $reservation->user_snapshot,
                    'vehicle_snapshot' => $reservation->vehicle_snapshot,
                    'reservation_snapshot' => [
                        'reservation_code' => $reservation->reservation_code,
                        'start_time' => $reservation->start_time?->toIso8601String(),
                        'check_in_at' => $reservation->check_in_at?->toIso8601String(),
                    ],
                ]);
                $violations[] = $violation;
            } else {
                $violations[] = [
                    'reservation_id' => $reservation->id,
                    'late_minutes' => $lateMinutes,
                ];
            }
        }

        return response()->json([
            'detected' => count($violations),
            'violations_created' => $r->get('auto_create', false) ? count($violations) : 0,
            'data' => $violations,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/violations/detect-no-show",
     *     tags={"🚨 Violations"},
     *     summary="Tự động phát hiện không đến (NO_SHOW)",
     *     description="Phát hiện reservations đã hết hạn nhưng không check-in",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="auto_create", type="boolean", default=false)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Danh sách vi phạm")
     * )
     */
    public function detectNoShow(Request $r)
    {
        $query = Reservation::with('slot')
            ->where('status', 'expired')
            ->whereNull('check_in_at');

        $violations = [];
        $reservations = $query->get();

        foreach ($reservations as $reservation) {
            // Kiểm tra đã có violation chưa
            $existing = Violation::where('reservation_id', $reservation->id)
                ->where('type', 'NO_SHOW')
                ->first();

            if ($existing) {
                continue;
            }

            if ($r->get('auto_create', false)) {
                $slot = $reservation->slot;
                if (!$slot) {
                    continue; // Bỏ qua nếu không có slot
                }

                $violation = Violation::create([
                    'user_id' => $reservation->user_id,
                    'vehicle_id' => $reservation->vehicle_id,
                    'reservation_id' => $reservation->id,
                    'parking_lot_id' => $slot->parking_lot_id,
                    'slot_id' => $reservation->slot_id,
                    'type' => 'NO_SHOW',
                    'severity' => 'LOW',
                    'status' => 'PENDING',
                    'description' => "Không đến đỗ xe sau khi đặt chỗ (reservation expired: {$reservation->expires_at?->format('Y-m-d H:i')})",
                    'violation_time' => $reservation->expires_at,
                    'ticket_number' => 'VP-' . now()->format('Ymd') . '-' . str_pad(rand(1, 9999), 5, '0', STR_PAD_LEFT),
                    'user_snapshot' => $reservation->user_snapshot,
                    'vehicle_snapshot' => $reservation->vehicle_snapshot,
                    'reservation_snapshot' => [
                        'reservation_code' => $reservation->reservation_code,
                        'start_time' => $reservation->start_time?->toIso8601String(),
                        'expires_at' => $reservation->expires_at?->toIso8601String(),
                    ],
                ]);
                $violations[] = $violation;
            } else {
                $violations[] = [
                    'reservation_id' => $reservation->id,
                    'reservation_code' => $reservation->reservation_code,
                ];
            }
        }

        return response()->json([
            'detected' => count($violations),
            'violations_created' => $r->get('auto_create', false) ? count($violations) : 0,
            'data' => $violations,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/violations/detect-late-payment",
     *     tags={"🚨 Violations"},
     *     summary="Tự động phát hiện thanh toán phạt chậm",
     *     description="Phát hiện violations có fine_amount nhưng chưa thanh toán sau N ngày",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="payment_deadline_days", type="integer", default=7, description="Số ngày deadline thanh toán"),
     *             @OA\Property(property="auto_update", type="boolean", default=false, description="Tự động cập nhật type thành LATE_PAYMENT")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Danh sách violations chậm thanh toán")
     * )
     */
    public function detectLatePayment(Request $r)
    {
        $deadlineDays = $r->get('payment_deadline_days', 7);
        $deadlineDate = now()->subDays($deadlineDays);

        $violations = Violation::where('status', 'PENDING')
            ->whereNotNull('fine_amount')
            ->where('fine_amount', '>', 0)
            ->whereNull('payment_id')
            ->where('created_at', '<', $deadlineDate)
            ->where('type', '!=', 'LATE_PAYMENT') // Tránh duplicate
            ->get();

        if ($r->get('auto_update', false)) {
            foreach ($violations as $violation) {
                $violation->update([
                    'type' => 'LATE_PAYMENT',
                    'description' => ($violation->description ?? '') . ' - Chưa thanh toán phạt sau ' . $deadlineDays . ' ngày',
                    'severity' => 'HIGH',
                ]);
            }
        }

        return response()->json([
            'detected' => $violations->count(),
            'updated' => $r->get('auto_update', false) ? $violations->count() : 0,
            'data' => $violations,
        ]);
    }

    // Helper methods
    private function calculateOverstayFine(int $overstayMinutes, Reservation $reservation): int
    {
        // Logic tính phí: ví dụ 10,000 VND/phút đầu, 20,000 VND/phút sau
        if ($overstayMinutes <= 30) {
            return $overstayMinutes * 10000;
        }
        return (30 * 10000) + (($overstayMinutes - 30) * 20000);
    }

    private function determineSeverity(int $minutes): string
    {
        if ($minutes <= 15) return 'LOW';
        if ($minutes <= 60) return 'MEDIUM';
        if ($minutes <= 120) return 'HIGH';
        return 'CRITICAL';
    }
}

