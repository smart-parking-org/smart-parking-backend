<?php

namespace App\Http\Controllers;

use App\Models\CheckoutCode;
use App\Models\MonthlyPass;
use App\Models\Payment;
use App\Models\Violation;
use App\Models\Reservation;
use App\Models\ReservationRequest;
use App\Services\ParkingFeeService;
use App\Services\VnpayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA; // <-- Quan trọng cho swagger-php

/**
 * @OA\Tag(name="💳 Payments", description="Payment operations via VNPAY")
 */
class PaymentController extends Controller
{
    public function __construct(private VnpayService $vnp)
    {
    }

    /**
     * @OA\Post(
     *   path="/payments/create",
     *   operationId="PaymentsCreate",
     *   tags={"Payments"},
     *   summary="Tạo payment cho reservation (online hoặc offline)",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"order_id","reservation_id","payment_method"},
     *       @OA\Property(property="order_id", type="string", example="INV-10001"),
     *       @OA\Property(property="reservation_id", type="integer", example=123, description="ID của reservation"),
     *       @OA\Property(property="payment_method", type="string", enum={"online", "offline"}, example="online", description="Phương thức thanh toán"),
     *       @OA\Property(property="bank_code", type="string", nullable=true, example="NCB", description="Mã ngân hàng (chỉ dùng cho online)")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Tạo payment thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="payUrl", type="string", nullable=true, description="URL thanh toán VNPAY (chỉ có khi payment_method=online)"),
     *       @OA\Property(property="txnRef", type="string"),
     *       @OA\Property(property="payment_id", type="integer"),
     *       @OA\Property(property="amount", type="integer", example=50000),
     *       @OA\Property(property="payment_method", type="string", example="online"),
     *       @OA\Property(property="status", type="string", example="PENDING")
     *     )
     *   ),
     *   @OA\Response(response=404, description="Reservation not found"),
     *   @OA\Response(response=422, description="Validation error hoặc reservation chưa check-in")
     * )
     */
    public function create(Request $r)
    {
        $r->validate([
            'order_id' => 'required',
            'reservation_id' => 'required|integer|exists:reservations,id',
            'payment_method' => 'required|in:online,offline',
            'bank_code' => 'nullable|string',
        ]);

        $reservation = Reservation::with('slot')->find($r->reservation_id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }

        // Chỉ cho phép thanh toán khi đã check-in
        if (!$reservation->check_in_at) {
            return response()->json([
                'message' => 'Reservation chưa check-in, không thể thanh toán'
            ], 422);
        }

        $paymentMethod = $r->payment_method; // 'online' hoặc 'offline'

        // Kiểm tra xem đã có payment PENDING cho reservation này chưa
        $existingPayment = Payment::where('reservation_id', $r->reservation_id)
            ->where('status', 'PENDING')
            ->first();

        // ✅ Kiểm tra nếu đã có payment PAID
        $paidPayment = Payment::where('reservation_id', $r->reservation_id)
            ->where('status', 'PAID')
            ->first();

        if ($paidPayment) {
            return response()->json([
                'message' => 'Reservation này đã thanh toán thành công. Không thể tạo payment mới.',
                'payment_id' => $paidPayment->id,
                'status' => $paidPayment->status,
            ], 422);
        }

        // Tính phí dựa trên logic reservation
        $parkingLotId = $reservation->slot->parking_lot_id;
        $vehicleType = $reservation->vehicle_snapshot['vehicle_type'] ?? 'motorbike';
        $checkOutAt = $reservation->check_out_at ?? now();

        $feeService = app(ParkingFeeService::class);
        $amount = $feeService->calculateReservationFee(
            $reservation->start_time,
            $reservation->end_time,
            $checkOutAt,
            $parkingLotId,
            $vehicleType,
            $reservation->user_id,
            $reservation->vehicle_id
        );

        // Nếu đã có payment PENDING
        if ($existingPayment) {
            $paymentAge = $existingPayment->created_at->diffInMinutes(now());
            $isPaymentExpired = $paymentAge > 15; // Payment đã tồn tại quá 15 phút

            // Nếu là online payment hoặc payment đã quá 15 phút, tạo payment mới
            if ($paymentMethod === 'online' || $isPaymentExpired) {
                // Chuyển payment cũ sang FAILED để tránh xung đột
                $existingPayment->update(['status' => 'FAILED']);

                // Tạo payment mới với txn_ref mới
                $orderId = $reservation->reservation_code;
                $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);

                // Kiểm tra txn_ref đã tồn tại chưa
                while (Payment::where('txn_ref', $txnRef)->exists()) {
                    $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);
                }

                $p = Payment::create([
                    'order_id' => $r->order_id,
                    'reservation_id' => $r->reservation_id,
                    'amount' => $amount,
                    'txn_ref' => $txnRef,
                    'status' => 'PENDING',
                    'meta' => [
                        'type' => 'parking_fee',
                        'payment_method' => $paymentMethod,
                        'reservation_id' => $reservation->id,
                        'check_in_at' => $reservation->check_in_at->toIso8601String(),
                        'check_out_at' => $checkOutAt->toIso8601String(),
                        'previous_payment_id' => $existingPayment->id, // Lưu payment cũ để trace
                    ],
                ]);

                // ✅ Cập nhật reservation->payment_id để trỏ đến payment mới
                $reservation->update(['payment_id' => $p->id]);

                $response = [
                    'txnRef' => $txnRef,
                    'payment_id' => $p->id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                    'status' => $p->status,
                ];

                if ($paymentMethod === 'online') {
                    $response['message'] = 'Tạo payment mới với txn_ref mới để tránh lỗi VNPAY';
                } else {
                    $response['message'] = 'Tạo payment mới do payment cũ đã quá thời gian';
                }

                // Chỉ tạo VNPAY URL nếu là online payment
                if ($paymentMethod === 'online') {
                    $url = $this->vnp->createPaymentUrl([
                        'order_id' => $p->order_id,
                        'amount' => $p->amount,
                        'txn_ref' => $p->txn_ref,
                        'bank_code' => $r->bank_code,
                    ]);
                    $response['payUrl'] = $url;
                }

                return response()->json($response);
            }

            // Nếu payment còn mới (< 15 phút) và là offline, tái sử dụng
            // Cập nhật amount nếu khác
            if ($existingPayment->amount !== $amount) {
                $existingPayment->update(['amount' => $amount]);
            }

            // Cập nhật meta với thời gian mới nhất và payment_method
            $existingMeta = is_array($existingPayment->meta) ? $existingPayment->meta : [];
            $existingPayment->update([
                'meta' => array_merge($existingMeta, [
                    'type' => 'parking_fee',
                    'payment_method' => $paymentMethod,
                    'reservation_id' => $reservation->id,
                    'check_in_at' => $reservation->check_in_at->toIso8601String(),
                    'check_out_at' => $checkOutAt->toIso8601String(),
                ]),
            ]);

            $response = [
                'txnRef' => $existingPayment->txn_ref,
                'payment_id' => $existingPayment->id,
                'amount' => $existingPayment->amount,
                'payment_method' => $paymentMethod,
                'status' => $existingPayment->status,
                'message' => 'Sử dụng payment đã tồn tại'
            ];

            return response()->json($response);
        }

        // ✅ Kiểm tra nếu có payment FAILED, tạo txn_ref mới
        $failedPayment = Payment::where('reservation_id', $r->reservation_id)
            ->where('status', 'FAILED')
            ->latest()
            ->first();

        // Tạo payment mới
        $orderId = $reservation->reservation_code;

        // ✅ Tạo txn_ref mới, đảm bảo unique
        $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);
        // Kiểm tra txn_ref đã tồn tại chưa (rất hiếm nhưng để an toàn)
        while (Payment::where('txn_ref', $txnRef)->exists()) {
            $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);
        }

        $p = Payment::create([
            'order_id' => $r->order_id,
            'reservation_id' => $r->reservation_id,
            'amount' => $amount,
            'txn_ref' => $txnRef,
            'status' => 'PENDING',
            'meta' => [
                'type' => 'parking_fee',
                'payment_method' => $paymentMethod,
                'reservation_id' => $reservation->id,
                'check_in_at' => $reservation->check_in_at->toIso8601String(),
                'check_out_at' => $checkOutAt->toIso8601String(),
            ],
        ]);

        // ✅ Cập nhật reservation->payment_id để trỏ đến payment mới
        $reservation->update(['payment_id' => $p->id]);

        $response = [
            'txnRef' => $txnRef,
            'payment_id' => $p->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'status' => $p->status,
        ];

        // Chỉ tạo VNPAY URL nếu là online payment
        if ($paymentMethod === 'online') {
            $url = $this->vnp->createPaymentUrl([
                'order_id' => $p->order_id,
                'amount' => $p->amount,
                'txn_ref' => $p->txn_ref,
                'bank_code' => $r->bank_code,
            ]);
            $response['payUrl'] = $url;
        }

        return response()->json($response);
    }

    /**
     * @OA\Get(
     *   path="/payments/return",
     *   operationId="PaymentsReturnGET",
     *   tags={"Payments"},
     *   summary="Return URL (GET) từ VNPAY",
     *   @OA\Parameter(name="vnp_TxnRef", in="query", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="vnp_Amount", in="query", required=true, @OA\Schema(type="integer", example=5000000)),
     *   @OA\Parameter(name="vnp_ResponseCode", in="query", required=true, @OA\Schema(type="string", example="00")),
     *   @OA\Response(
     *     response=200,
     *     description="Kết quả giao dịch",
     *     @OA\JsonContent(
     *       @OA\Property(property="status",   type="string", example="PAID"),
     *       @OA\Property(property="order_id", type="string"),
     *       @OA\Property(property="txn_ref",  type="string"),
     *       @OA\Property(property="message",  type="string")
     *     )
     *   ),
     *   @OA\Response(response=400, description="Invalid checksum / Amount mismatch"),
     *   @OA\Response(response=404, description="Order not found")
     * )
     *
     * @OA\Post(
     *   path="/payments/return",
     *   operationId="PaymentsReturnPOST",
     *   tags={"Payments"},
     *   summary="Return URL (POST) từ VNPAY",
     *   @OA\RequestBody(
     *     required=true,
     *     content={
     *       "application/x-www-form-urlencoded"=@OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *           type="object",
     *           required={"vnp_TxnRef","vnp_Amount","vnp_ResponseCode"},
     *           @OA\Property(property="vnp_TxnRef", type="string"),
     *           @OA\Property(property="vnp_Amount", type="integer", example=5000000),
     *           @OA\Property(property="vnp_ResponseCode", type="string", example="00")
     *         )
     *       )
     *     }
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Kết quả giao dịch",
     *     @OA\JsonContent(
     *       @OA\Property(property="status",   type="string", example="PAID"),
     *       @OA\Property(property="order_id", type="string"),
     *       @OA\Property(property="txn_ref",  type="string"),
     *       @OA\Property(property="message",  type="string")
     *     )
     *   ),
     *   @OA\Response(response=400, description="Invalid checksum / Amount mismatch"),
     *   @OA\Response(response=404, description="Order not found")
     * )
     */
    public function return(Request $r)
    {
        $params = $r->all();
        Log::info('VNPAY RETURN', $params);

        if (!$this->vnp->verify($params)) {
            return view('payment.error', [
                'title' => 'Lỗi',
                'message' => 'Chữ ký không hợp lệ',
                'icon' => '❌',
                'color' => '#ef4444'
            ])->header('Content-Type', 'text/html; charset=utf-8');
        }

        $payment = Payment::where('txn_ref', $params['vnp_TxnRef'] ?? '')->first();
        if (!$payment) {
            return view('payment.error', [
                'title' => 'Lỗi',
                'message' => 'Không tìm thấy đơn hàng',
                'icon' => '❌',
                'color' => '#ef4444'
            ])->header('Content-Type', 'text/html; charset=utf-8');
        }

        $amountVnp = (int) ($params['vnp_Amount'] ?? 0) / 100;
        if ($amountVnp !== (int) $payment->amount) {
            return view('payment.error', [
                'title' => 'Lỗi',
                'message' => 'Số tiền không khớp',
                'icon' => '❌',
                'color' => '#ef4444'
            ])->header('Content-Type', 'text/html; charset=utf-8');
        }

        $wasPaid = false;
        if ($payment->status === 'PENDING') {
            $newStatus = ($params['vnp_ResponseCode'] === '00') ? 'PAID' : 'FAILED';
            $wasPaid = ($newStatus === 'PAID');

            // Giữ nguyên và merge thêm thông tin từ VNPay
            $existingMeta = is_array($payment->meta) ? $payment->meta : [];
            $mergedMeta = array_merge($existingMeta, [
                'vnp_return' => $params,
            ]);

            $payment->update([
                'status' => $newStatus,
                'vnp_response_code' => $params['vnp_ResponseCode'] ?? null,
                'vnp_transaction_no' => $params['vnp_TransactionNo'] ?? null,
                'bank_code' => $params['vnp_BankCode'] ?? null,
                'card_type' => $params['vnp_CardType'] ?? null,
                'meta' => $mergedMeta,
            ]);
        }

        // Xử lý các loại payment sau khi thanh toán thành công
        if ($wasPaid) {
            // Reload payment 
            $payment->refresh();
            if ($payment->meta && isset($payment->meta['type'])) {
                if ($payment->meta['type'] === 'monthly_pass') {
                    $this->activateMonthlyPass($payment);
                } elseif ($payment->meta['type'] === 'violation_fine') {
                    $this->resolveViolation($payment);
                }
            }

            // ✅ Chuyển reservation từ pending_payment → pending_checkout nếu có reservation
            if ($payment->reservation_id) {
                $reservation = Reservation::find($payment->reservation_id);
                if ($reservation && $reservation->status === 'pending_payment') {
                    $reservation->update(['status' => 'pending_checkout']);
                }
            }

            // Mobile app sẽ nhận deep link với status=PAID để biết thanh toán thành công
        }

        // ✅ Chuẩn bị data cho view
        $status = $payment->status;
        $isSuccess = $status === 'PAID';

        $title = $isSuccess ? 'Thanh toán thành công!' : 'Thanh toán thất bại';
        $message = $isSuccess
            ? 'Cảm ơn bạn đã thanh toán. Vui lòng quay lại app để hoàn tất.'
            : 'Thanh toán không thành công. Vui lòng thử lại hoặc chọn phương thức thanh toán khác.';
        $icon = $isSuccess ? '✅' : '❌';
        $color = $isSuccess ? '#10b981' : '#ef4444';

        // Tạo deep link
        $deepLink = "smartparking://payment/result?status={$status}&txn_ref={$payment->txn_ref}&order_id={$payment->order_id}&reservation_id={$payment->reservation_id}";

        // ✅ Thêm monthly_pass_id vào deep link nếu có
        if ($payment->meta && isset($payment->meta['monthly_pass_id'])) {
            $deepLink .= "&monthly_pass_id=" . $payment->meta['monthly_pass_id'];
        } else {
            // ✅ Fallback: Nếu order_id bắt đầu bằng "MP-", tìm monthly pass theo order_id
            if (str_starts_with($payment->order_id, 'MP-')) {
                $monthlyPass = MonthlyPass::where('order_id', $payment->order_id)->first();
                if ($monthlyPass) {
                    $deepLink .= "&monthly_pass_id=" . $monthlyPass->id;
                }
            }
        }

        // ✅ Return view với data
        return view('payment.return', [
            'title' => $title,
            'message' => $message,
            'icon' => $icon,
            'color' => $color,
            'deepLink' => $deepLink,
            'payment' => $payment,
        ]);
    }
    /**
     * @OA\Post(
     *   path="/payments/ipn",
     *   operationId="PaymentsIpnPOST",
     *   tags={"Payments"},
     *   summary="IPN (POST) từ VNPAY",
     *   @OA\RequestBody(
     *     required=true,
     *     content={
     *       "application/x-www-form-urlencoded"=@OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *           type="object",
     *           required={"vnp_TxnRef","vnp_Amount","vnp_ResponseCode"},
     *           @OA\Property(property="vnp_TxnRef", type="string"),
     *           @OA\Property(property="vnp_Amount", type="integer", example=5000000),
     *           @OA\Property(property="vnp_ResponseCode", type="string", example="00")
     *         )
     *       )
     *     }
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="VNPay expects JSON {RspCode, Message}",
     *     @OA\JsonContent(
     *       @OA\Property(property="RspCode", type="string", example="00"),
     *       @OA\Property(property="Message", type="string", example="Confirm Success")
     *     )
     *   )
     * )
     *
     * @OA\Get(
     *   path="/payments/ipn",
     *   operationId="PaymentsIpnGET",
     *   tags={"Payments"},
     *   summary="IPN (GET) cho SIT",
     *   @OA\Parameter(name="vnp_TxnRef", in="query", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="vnp_Amount", in="query", required=true, @OA\Schema(type="integer", example=5000000)),
     *   @OA\Parameter(name="vnp_ResponseCode", in="query", required=true, @OA\Schema(type="string", example="00")),
     *   @OA\Response(
     *     response=200,
     *     description="VNPay expects JSON {RspCode, Message}",
     *     @OA\JsonContent(
     *       @OA\Property(property="RspCode", type="string", example="00"),
     *       @OA\Property(property="Message", type="string", example="Confirm Success")
     *     )
     *   )
     * )
     */
    public function ipn(Request $r)
    {
        $params = $r->all();
        Log::info('VNPAY IPN', $params);

        if (!$this->vnp->verify($params)) {
            return response()->json(['RspCode' => '97', 'Message' => 'Invalid Checksum']);
        }

        $payment = Payment::where('txn_ref', $params['vnp_TxnRef'] ?? '')->first();
        if (!$payment) {
            return response()->json(['RspCode' => '01', 'Message' => 'Order not found']);
        }

        $amountVnp = (int) ($params['vnp_Amount'] ?? 0) / 100;
        if ($amountVnp !== (int) $payment->amount) {
            return response()->json(['RspCode' => '04', 'Message' => 'Invalid Amount']);
        }

        if ($payment->status !== 'PENDING') {
            return response()->json(['RspCode' => '02', 'Message' => 'Order already confirmed']);
        }

        $wasPaid = false;
        $newStatus = ($params['vnp_ResponseCode'] === '00') ? 'PAID' : 'FAILED';
        $wasPaid = ($newStatus === 'PAID');

        // Giữ nguyên meta cũ và merge thêm thông tin từ VNPay
        $existingMeta = is_array($payment->meta) ? $payment->meta : [];
        $mergedMeta = array_merge($existingMeta, [
            'vnp_ipn' => $params,
        ]);

        $payment->update([
            'status' => $newStatus,
            'vnp_response_code' => $params['vnp_ResponseCode'] ?? null,
            'vnp_transaction_no' => $params['vnp_TransactionNo'] ?? null,
            'bank_code' => $params['vnp_BankCode'] ?? null,
            'card_type' => $params['vnp_CardType'] ?? null,
            'meta' => $mergedMeta,
        ]);

        // Xử lý các loại payment sau khi thanh toán thành công
        if ($wasPaid) {
            // Reload payment để lấy meta đã được update
            $payment->refresh();

            // Xử lý monthly pass hoặc violation fine
            if ($payment->meta && isset($payment->meta['type'])) {
                if ($payment->meta['type'] === 'monthly_pass') {
                    $this->activateMonthlyPass($payment);
                } elseif ($payment->meta['type'] === 'violation_fine') {
                    $this->resolveViolation($payment);
                }
            }

            // ✅ Chuyển reservation từ pending_payment → pending_checkout nếu có reservation
            if ($payment->reservation_id) {
                $reservation = Reservation::find($payment->reservation_id);
                if ($reservation && $reservation->status === 'pending_payment') {
                    $reservation->update(['status' => 'pending_checkout']);
                }
            }

            // Mobile app sẽ nhận IPN callback với status=PAID để biết thanh toán thành công
        }

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
    }

    public function getByOrder($orderId)
    {
        $payment = Payment::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        return response()->json($payment);
    }

    /**
     * @OA\Put(
     *   path="/payments/{id}/confirm-offline",
     *   operationId="ConfirmOfflinePayment",
     *   tags={"💳 Payments"},
     *   summary="Xác nhận thanh toán trực tiếp (offline)",
     *   description="Nhân viên xác nhận thanh toán trực tiếp đã được thực hiện. Chỉ áp dụng cho payment có payment_method = 'offline' và status = 'PENDING'.",
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="ID của payment",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Xác nhận thanh toán thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Xác nhận thanh toán trực tiếp thành công"),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         @OA\Property(property="payment", type="object",
     *           @OA\Property(property="id", type="integer", example=123),
     *           @OA\Property(property="order_id", type="string", example="RES-AB12CD34-20251019"),
     *           @OA\Property(property="amount", type="integer", example=50000),
     *           @OA\Property(property="status", type="string", enum={"PENDING","PAID","FAILED"}, example="PAID"),
     *           @OA\Property(property="reservation_id", type="integer", example=456)
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Không tìm thấy payment",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=false),
     *       @OA\Property(property="message", type="string", example="Payment not found")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Không thể xác nhận thanh toán",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=false),
     *       @OA\Property(property="message", type="string", example="Chỉ có thể xác nhận thanh toán trực tiếp với status PENDING")
     *     )
     *   )
     * )
     */
    public function confirmOfflinePayment($id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        // Kiểm tra payment method phải là offline
        $paymentMethod = $payment->meta['payment_method'] ?? null;
        if ($paymentMethod !== 'offline') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể xác nhận thanh toán trực tiếp (offline). Payment này không phải offline payment.'
            ], 422);
        }

        // Kiểm tra status phải là PENDING
        if ($payment->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể xác nhận thanh toán với status PENDING. Payment hiện tại có status: ' . $payment->status
            ], 422);
        }

        // Cập nhật payment status thành PAID
        $existingMeta = is_array($payment->meta) ? $payment->meta : [];
        $payment->update([
            'status' => 'PAID',
            'paid_at' => now(),
            'meta' => array_merge($existingMeta, [
                'confirmed_at' => now()->toIso8601String(),
                'confirmed_by' => 'staff', // Có thể lấy từ auth user sau này
            ]),
        ]);

        // Reload payment để lấy meta đã được update
        $payment->refresh();

        // Xử lý các loại payment sau khi thanh toán thành công
        if ($payment->meta && isset($payment->meta['type'])) {
            if ($payment->meta['type'] === 'monthly_pass') {
                $this->activateMonthlyPass($payment);
            } elseif ($payment->meta['type'] === 'violation_fine') {
                $this->resolveViolation($payment);
            } elseif ($payment->meta['type'] === 'parking_fee' && $payment->reservation_id) {
                // ✅ Xử lý reservation cho parking fee
                $reservation = Reservation::find($payment->reservation_id);
                if ($reservation && in_array($reservation->status, ['pending_payment', 'pending_checkout'])) {
                    // ✅ Kiểm tra đã quét QR chưa (từ payment meta)
                    $qrScanned = isset($payment->meta['qr_scanned']) && $payment->meta['qr_scanned'] === true;

                    if ($reservation->status === 'pending_checkout' && $qrScanned) {
                        // Đã quét QR rồi → checked_out ngay
                        $reservation->update([
                            'status' => 'checked_out',
                        ]);

                        // ✅ Giải phóng slot (chỉ khi có slot)
                        if ($reservation->slot_id !== null && $reservation->slot) {
                            $reservation->slot->update(['status' => 'available']);
                        }

                        // Finalize request nếu có
                        $this->finalizeReservationRequest($reservation);

                        // ✅ Đánh dấu checkout code đã sử dụng nếu có
                        $checkoutCode = CheckoutCode::where('reservation_id', $reservation->id)
                            ->where('status', 'active')
                            ->latest()
                            ->first();
                        if ($checkoutCode) {
                            $checkoutCode->markAsUsed();
                        }
                    } elseif ($reservation->status === 'pending_payment') {
                        // Chưa quét QR → chuyển sang pending_checkout (chờ quét QR)
                        $reservation->update([
                            'status' => 'pending_checkout',
                        ]);
                    }
                    // Nếu đã pending_checkout nhưng chưa quét QR → giữ nguyên, chờ quét QR
                }
            }
        }

        Log::info('Offline payment confirmed', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount' => $payment->amount,
            'reservation_id' => $payment->reservation_id,
        ]);

        $response = [
            'success' => true,
            'message' => 'Xác nhận thanh toán trực tiếp thành công',
            'data' => [
                'payment' => $payment,
            ]
        ];

        // Thêm thông tin reservation nếu có
        if ($payment->reservation_id) {
            $reservation = Reservation::find($payment->reservation_id);
            if ($reservation) {
                $response['data']['reservation'] = $reservation;
            }
        }

        return response()->json($response);
    }

    /**
     * Kích hoạt vé tháng sau khi thanh toán thành công
     */
    private function activateMonthlyPass(Payment $payment): void
    {
        try {
            $passId = $payment->meta['monthly_pass_id'] ?? null;
            if (!$passId) {
                Log::warning('MonthlyPass ID not found in payment meta', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                ]);
                return;
            }

            $pass = MonthlyPass::find($passId);
            if (!$pass) {
                Log::warning('MonthlyPass not found', [
                    'monthly_pass_id' => $passId,
                    'payment_id' => $payment->id,
                ]);
                return;
            }

            if ($pass->status !== 'PENDING') {
                Log::info('MonthlyPass already processed', [
                    'monthly_pass_id' => $passId,
                    'current_status' => $pass->status,
                ]);
                return;
            }

            // Tính ngày bắt đầu và kết thúc
            $startDate = $pass->start_date ?: now()->toDateString();
            $endDate = Carbon::parse($startDate)->addMonthsNoOverflow($pass->months)->toDateString();

            $pass->update([
                'status' => 'ACTIVE',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            Log::info('MonthlyPass activated successfully', [
                'monthly_pass_id' => $pass->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'payment_id' => $payment->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to activate MonthlyPass', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Tự động xử lý vi phạm sau khi thanh toán thành công
     */
    private function resolveViolation(Payment $payment): void
    {
        try {
            $violationId = $payment->meta['violation_id'] ?? null;
            if (!$violationId) {
                Log::warning('Violation ID not found in payment meta', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                ]);
                return;
            }

            $violation = Violation::find($violationId);
            if (!$violation) {
                Log::warning('Violation not found', [
                    'violation_id' => $violationId,
                    'payment_id' => $payment->id,
                ]);
                return;
            }

            if ($violation->status !== 'PENDING') {
                Log::info('Violation already processed', [
                    'violation_id' => $violationId,
                    'current_status' => $violation->status,
                ]);
                return;
            }

            // Cập nhật violation status thành RESOLVED
            $violation->update([
                'status' => 'RESOLVED',
                'resolved_at' => now(),
                'resolved_by' => $payment->meta['user_id'] ?? null,
                'resolution_note' => 'Tự động xử lý sau khi thanh toán phạt thành công',
            ]);

            Log::info('Violation resolved successfully after payment', [
                'violation_id' => $violation->id,
                'payment_id' => $payment->id,
                'fine_amount' => $payment->amount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resolve Violation after payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Tạo QR checkout code sau khi thanh toán thành công
     */
    private function createCheckoutCode(\App\Models\Reservation $reservation, Payment $payment): CheckoutCode
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
     * Finalize reservation request nếu có
     */
    private function finalizeReservationRequest(Reservation $reservation, $finalStatus = 'completed'): void
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
