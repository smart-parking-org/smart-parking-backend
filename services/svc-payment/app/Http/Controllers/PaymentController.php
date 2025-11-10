<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\VnpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     *   summary="Tạo URL thanh toán VNPAY",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"order_id","amount"},
     *       @OA\Property(property="order_id", type="string", example="INV-10001"),
     *       @OA\Property(property="amount", type="integer", example=50000, minimum=1000),
     *       @OA\Property(property="bank_code", type="string", nullable=true, example="NCB")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Sinh link thanh toán thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="payUrl", type="string"),
     *       @OA\Property(property="txnRef", type="string")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function create(Request $r)
    {
        $r->validate([
            'order_id' => 'required',
            'amount' => 'required|integer|min:1000',
        ]);

        $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);

        $p = Payment::create([
            'order_id' => $r->order_id,
            'amount' => $r->amount,
            'txn_ref' => $txnRef,
            'status' => 'PENDING',
        ]);

        $url = $this->vnp->createPaymentUrl([
            'order_id' => $p->order_id,
            'amount' => $p->amount,
            'txn_ref' => $p->txn_ref,
            'bank_code' => $r->bank_code,
        ]);

        return response()->json(['payUrl' => $url, 'txnRef' => $txnRef]);
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

        // Cập nhật status payment
        if ($payment->status === 'PENDING') {
            $payment->update([
                'status' => ($params['vnp_ResponseCode'] === '00') ? 'PAID' : 'FAILED',
                'vnp_response_code' => $params['vnp_ResponseCode'] ?? null,
                'vnp_transaction_no' => $params['vnp_TransactionNo'] ?? null,
                'bank_code' => $params['vnp_BankCode'] ?? null,
                'card_type' => $params['vnp_CardType'] ?? null,
                'meta' => $params,
            ]);
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
        $deepLink = "smartparkingmobile://payment/result?status={$status}&txn_ref={$payment->txn_ref}&order_id={$payment->order_id}";

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
     * @OA@Get(
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

        $paymentStatus = ($params['vnp_ResponseCode'] === '00') ? 'PAID' : 'FAILED';

        $payment->update([
            'status' => $paymentStatus,
            'vnp_response_code' => $params['vnp_ResponseCode'] ?? null,
            'vnp_transaction_no' => $params['vnp_TransactionNo'] ?? null,
            'bank_code' => $params['vnp_BankCode'] ?? null,
            'card_type' => $params['vnp_CardType'] ?? null,
            'meta' => $params,
        ]);

        // checkout khi thanh toán thành công
        if ($paymentStatus === 'PAID') {
            $reservation = \App\Models\Reservation::where('reservation_code', $payment->order_id)->first();

            if ($reservation && $reservation->status === 'checked_in') {
                // Gọi checkout
                $reservationController = new \App\Http\Controllers\ReservationController(
                    app(\App\Services\AuthService::class)
                );

                try {
                    $checkoutResult = $reservationController->checkOut($reservation->id);
                    Log::info('Auto checkout after payment success', [
                        'reservation_id' => $reservation->id,
                        'reservation_code' => $reservation->reservation_code
                    ]);
                } catch (\Exception $e) {
                    Log::error('Auto checkout failed', [
                        'reservation_id' => $reservation->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
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
}
