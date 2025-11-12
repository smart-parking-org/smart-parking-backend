<?php

namespace App\Http\Controllers;

use App\Models\MonthlyPass;
use App\Models\PricingRule;
use App\Models\Payment;
use App\Services\AuthService;
use App\Services\VnpayService;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="🎫 Monthly Passes",
 *     description="Quản lý vé tháng cho người dùng"
 * )
 */
class MonthlyPassController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private VnpayService $vnpayService
    ) {
    }

    /**
     * @OA\Post(
     *     path="/monthly-passes",
     *     tags={"🎫 Monthly Passes"},
     *     summary="Tạo vé tháng và URL thanh toán",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "vehicle_id", "parking_lot_id"},
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="vehicle_id", type="integer", example=10),
     *             @OA\Property(property="parking_lot_id", type="integer", example=2),
     *             @OA\Property(property="months", type="integer", example=1, description="Số tháng (1-12)", minimum=1, maximum=12),
     *             @OA\Property(property="start_date", type="string", format="date", nullable=true, example="2025-11-01"),
     *             @OA\Property(property="bank_code", type="string", nullable=true, example="NCB")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tạo vé tháng thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="order_id", type="string", example="MP-20251029120000-12345"),
     *             @OA\Property(property="txn_ref", type="string", example="ORD20251029120000123"),
     *             @OA\Property(property="amount", type="integer", example=1800000),
     *             @OA\Property(property="payUrl", type="string", example="https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?...")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error hoặc lỗi business logic")
     * )
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'user_id' => 'required|integer',
            'vehicle_id' => 'required|integer',
            'parking_lot_id' => 'required|integer',
            'months' => 'nullable|integer|min:1|max:12',
            'start_date' => 'nullable|date',
            'bank_code' => 'nullable|string',
        ]);

        $months = (int)($data['months'] ?? 1);

        // Validate user + vehicle từ svc-auth
        $user = $this->authService->getUserSnapshot($data['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User không tồn tại hoặc không active'], 422);
        }

        $vehicle = $this->authService->getVehicleSnapshot($data['vehicle_id']);
        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle không tồn tại hoặc không active'], 422);
        }

        // Lấy giá monthly_pass từ PricingRule theo bãi + loại xe
        $rule = PricingRule::where('parking_lot_id', $data['parking_lot_id'])
            ->where('vehicle_type', $vehicle['vehicle_type'])
            ->first();

        if (!$rule || $rule->monthly_pass <= 0) {
            return response()->json(['message' => 'Bãi/loại xe chưa có giá vé tháng'], 422);
        }

        $amount = (int)round($rule->monthly_pass * $months);

        $orderId = 'MP-' . now()->format('YmdHis') . '-' . rand(10000, 99999);
        $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);

        // Tạo MonthlyPass với status PENDING
        $pass = MonthlyPass::create([
            'user_id' => $data['user_id'],
            'vehicle_id' => $data['vehicle_id'],
            'parking_lot_id' => $data['parking_lot_id'],
            'months' => $months,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => null,
            'amount' => $amount,
            'status' => 'PENDING',
            'order_id' => $orderId,
            'txn_ref' => $txnRef,
            'user_snapshot' => $user,
            'vehicle_snapshot' => $vehicle,
        ]);

        // Tạo Payment record TRƯỚC (giống PaymentController::create)
        Payment::create([
            'order_id' => $orderId,
            'amount' => $amount,
            'txn_ref' => $txnRef,
            'status' => 'PENDING',
            'meta' => [
                'type' => 'monthly_pass',
                'monthly_pass_id' => $pass->id,
                'user_id' => $data['user_id'],
                'vehicle_id' => $data['vehicle_id'],
                'parking_lot_id' => $data['parking_lot_id'],
            ],
        ]);

        // Sinh URL thanh toán VNPay
        $payUrl = $this->vnpayService->createPaymentUrl([
            'order_id' => $orderId,
            'amount' => $amount,
            'txn_ref' => $txnRef,
            'bank_code' => $data['bank_code'] ?? null,
        ]);

        return response()->json([
            'id' => $pass->id,
            'order_id' => $orderId,
            'txn_ref' => $txnRef,
            'amount' => $amount,
            'payUrl' => $payUrl,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/monthly-passes",
     *     tags={"🎫 Monthly Passes"},
     *     summary="Lấy danh sách tất cả vé tháng (có phân trang và filter)",
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=false,
     *         description="Lọc theo user_id",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="Lọc theo status",
     *         @OA\Schema(type="string", enum={"PENDING", "ACTIVE", "CANCELLED", "EXPIRED", "FAILED"})
     *     ),
     *     @OA\Parameter(
     *         name="parking_lot_id",
     *         in="query",
     *         required=false,
     *         description="Lọc theo parking_lot_id",
     *         @OA\Schema(type="integer", example=1)
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
     *         description="Danh sách vé tháng",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/MonthlyPass")
     *             ),
     *             @OA\Property(
     *                 property="pagination",
     *                 type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="per_page", type="integer", example=15),
     *                 @OA\Property(property="total", type="integer", example=45),
     *                 @OA\Property(property="last_page", type="integer", example=3)
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $r)
    {
        $query = MonthlyPass::query();

        // Filter theo user_id
        if ($r->filled('user_id')) {
            $query->where('user_id', $r->user_id);
        }

        // Filter theo status
        if ($r->filled('status')) {
            $query->where('status', $r->status);
        }

        // Filter theo parking_lot_id
        if ($r->filled('parking_lot_id')) {
            $query->where('parking_lot_id', $r->parking_lot_id);
        }

        // Sort mặc định: mới nhất trước
        $query->orderByDesc('id');

        // Pagination
        $perPage = $r->get('per_page', 15);
        $passes = $query->paginate($perPage);

        return response()->json([
            'data' => $passes->items(),
            'pagination' => [
                'current_page' => $passes->currentPage(),
                'per_page' => $passes->perPage(),
                'total' => $passes->total(),
                'last_page' => $passes->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/monthly-passes/mine",
     *     tags={"🎫 Monthly Passes"},
     *     summary="Lấy danh sách vé tháng của user",
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách vé tháng",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MonthlyPass"))
     *         )
     *     )
     * )
     */
    public function mine(Request $r)
    {
        $r->validate([
            'user_id' => 'required|integer',
        ]);

        $list = MonthlyPass::where('user_id', $r->user_id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $list]);
    }

    /**
     * @OA\Get(
     *     path="/monthly-passes/{id}",
     *     tags={"🎫 Monthly Passes"},
     *     summary="Chi tiết vé tháng",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chi tiết vé tháng",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/MonthlyPass")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vé tháng")
     * )
     */
    public function show($id)
    {
        $pass = MonthlyPass::find($id);
        if (!$pass) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $pass]);
    }

    /**
     * @OA\Put(
     *     path="/monthly-passes/{id}/cancel",
     *     tags={"🎫 Monthly Passes"},
     *     summary="Hủy vé tháng (chỉ khi status PENDING)",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Hủy thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", ref="#/components/schemas/MonthlyPass")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy vé tháng"),
     *     @OA\Response(response=422, description="Không thể hủy (không phải PENDING)")
     * )
     */
    public function cancel($id)
    {
        $pass = MonthlyPass::find($id);
        if (!$pass) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($pass->status !== 'PENDING') {
            return response()->json(['message' => 'Chỉ hủy khi đang PENDING'], 422);
        }

        $pass->update(['status' => 'CANCELLED']);

        // Cập nhật Payment status nếu còn PENDING
        $payment = Payment::where('txn_ref', $pass->txn_ref)->first();
        if ($payment && $payment->status === 'PENDING') {
            $payment->update(['status' => 'FAILED']);
        }

        return response()->json(['data' => $pass]);
    }
}

