<?php

namespace App\Http\Controllers;

use App\Models\MonthlyPass;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Violation;
use App\Services\ParkingFeeService;
use App\Services\VnpayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Annotations as OA; // <-- Quan trọng cho swagger-php

/**
 * @OA\Tag(name="Payments", description="Payment operations via VNPAY")
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
     *   summary="Tạo URL thanh toán VNPAY cho reservation",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"reservation_id"},
     *       @OA\Property(property="reservation_id", type="integer", example=101),
     *       @OA\Property(property="bank_code", type="string", nullable=true, example="NCB")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Sinh link thanh toán thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="payUrl", type="string"),
     *       @OA\Property(property="txnRef", type="string"),
     *       @OA\Property(property="payment_id", type="integer"),
     *       @OA\Property(property="amount", type="integer", example=50000)
     *     )
     *   ),
     *   @OA\Response(response=404, description="Reservation not found"),
     *   @OA\Response(response=422, description="Validation error hoặc reservation chưa check-in")
     * )
     */
    public function create(Request $r)
    {
        $r->validate([
            'reservation_id' => 'required|integer|exists:reservations,id',
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

        // Kiểm tra xem đã có payment PENDING cho reservation này chưa
        $existingPayment = Payment::where('reservation_id', $r->reservation_id)
            ->where('status', 'PENDING')
            ->first();

        if ($existingPayment) {
            // Tính lại phí (có thể thay đổi nếu thời gian đã tăng)
            $parkingLotId = $reservation->slot->parking_lot_id;
            $vehicleType = $reservation->vehicle_snapshot['vehicle_type'] ?? 'motorbike';
            $checkOutAt = $reservation->check_out_at ?? now();

            $feeService = app(ParkingFeeService::class);
            // $newAmount = $feeService->calculateFee(
            //     $reservation->check_in_at,
            //     $checkOutAt,
            //     $parkingLotId,
            //     $vehicleType
            // );
            $newAmount = 15000;

            // Cập nhật amount nếu khác
            if ($existingPayment->amount !== $newAmount) {
                $existingPayment->update(['amount' => $newAmount]);
            }

            // Cập nhật meta với thời gian mới nhất
            $existingMeta = is_array($existingPayment->meta) ? $existingPayment->meta : [];
            $existingPayment->update([
                'meta' => array_merge($existingMeta, [
                    'type' => 'parking_fee',
                    'reservation_id' => $reservation->id,
                    'check_in_at' => $reservation->check_in_at->toIso8601String(),
                    'check_out_at' => $checkOutAt->toIso8601String(),
                ]),
            ]);
            $orderId = $reservation->reservation_code;

            // Tạo URL thanh toán với payment đã tồn tại
            $url = $this->vnp->createPaymentUrl([
                'order_id' => $orderId,
                'amount' => $existingPayment->amount,
                'txn_ref' => $existingPayment->txn_ref,
                'bank_code' => $r->bank_code,
            ]);

            return response()->json([
                'payUrl' => $url,
                'txnRef' => $existingPayment->txn_ref,
                'payment_id' => $existingPayment->id,
                'amount' => $existingPayment->amount,
                'message' => 'Sử dụng payment đã tồn tại'
            ]);
        }

        // Nếu chưa có payment PENDING, tính phí và tạo mới
        $parkingLotId = $reservation->slot->parking_lot_id;
        $vehicleType = $reservation->vehicle_snapshot['vehicle_type'] ?? 'motorbike';
        $checkOutAt = $reservation->check_out_at ?? now();

        $feeService = app(ParkingFeeService::class);
        // $amount = $feeService->calculateFee(
        //     $reservation->check_in_at,
        //     $checkOutAt,
        //     $parkingLotId,
        //     $vehicleType
        // );
        $amount = 15000;

        $orderId = $reservation->reservation_code;
        $txnRef = 'ORD' . now()->format('YmdHis') . rand(100, 999);

        $p = Payment::create([
            'order_id' => $orderId,
            'amount' => $amount,
            'txn_ref' => $txnRef,
            'status' => 'PENDING',
            'reservation_id' => $reservation->id,
            'meta' => [
                'type' => 'parking_fee',
                'reservation_id' => $reservation->id,
                'check_in_at' => $reservation->check_in_at->toIso8601String(),
                'check_out_at' => $checkOutAt->toIso8601String(),
            ],
        ]);

        $url = $this->vnp->createPaymentUrl([
            'order_id' => $p->order_id,
            'amount' => $p->amount,
            'txn_ref' => $p->txn_ref,
            'bank_code' => $r->bank_code,
        ]);

        return response()->json([
            'payUrl' => $url,
            'txnRef' => $txnRef,
            'payment_id' => $p->id,
            'amount' => $amount
        ]);
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
            return response()->json(['message' => 'Invalid checksum'], 400);
        }

        $payment = Payment::where('txn_ref', $params['vnp_TxnRef'] ?? '')->first();
        if (!$payment) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $amountVnp = (int) ($params['vnp_Amount'] ?? 0) / 100;
        if ($amountVnp !== (int) $payment->amount) {
            return response()->json(['message' => 'Amount mismatch'], 400);
        }

        $wasPaid = false;
        if ($payment->status === 'PENDING') {
            $newStatus = ($params['vnp_ResponseCode'] === '00') ? 'PAID' : 'FAILED';
            $wasPaid = ($newStatus === 'PAID');

            // Giữ nguyên meta cũ và merge thêm thông tin từ VNPay
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
            // Reload payment để lấy meta đã được update
            $payment->refresh();
            if ($payment->meta && isset($payment->meta['type'])) {
                if ($payment->meta['type'] === 'monthly_pass') {
                    $this->activateMonthlyPass($payment);
                } elseif ($payment->meta['type'] === 'violation_fine') {
                    $this->resolveViolation($payment);
                } elseif ($payment->meta['type'] === 'parking_fee') {
                    Log::info('Parking fee paid successfully', [
                        'payment_id' => $payment->id,
                        'reservation_id' => $payment->meta['reservation_id'] ?? null,
                    ]);
                }
            }
        }

        return response()->json([
            'status' => $payment->status,
            'order_id' => $payment->order_id,
            'txn_ref' => $payment->txn_ref,
            'message' => $payment->status === 'PAID' ? 'Thanh toán thành công' : 'Thanh toán không thành công',
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
            if ($payment->meta && isset($payment->meta['type'])) {
                if ($payment->meta['type'] === 'monthly_pass') {
                    $this->activateMonthlyPass($payment);
                } elseif ($payment->meta['type'] === 'violation_fine') {
                    $this->resolveViolation($payment);
                } elseif ($payment->meta['type'] === 'parking_fee') {
                    Log::info('Parking fee paid successfully', [
                        'payment_id' => $payment->id,
                        'reservation_id' => $payment->meta['reservation_id'] ?? null,
                    ]);
                }
            }
        }

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
    }

    /**
     * @OA\Get(
     *   path="/payments/status/{reservation_id}",
     *   operationId="PaymentsStatus",
     *   tags={"Payments"},
     *   summary="Kiểm tra trạng thái thanh toán theo reservation (dùng cho mobile app sau khi quay lại từ webview)",
     *   @OA\Parameter(
     *     name="reservation_id",
     *     in="path",
     *     required=true,
     *     description="ID của reservation",
     *     @OA\Schema(type="integer", example=101)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Trạng thái thanh toán",
     *     @OA\JsonContent(
     *       @OA\Property(property="status", type="string", enum={"PENDING","PAID","FAILED"}, example="PAID"),
     *       @OA\Property(property="reservation_id", type="integer", example=101),
     *       @OA\Property(property="order_id", type="string", example="RES-AB12CD34-20251019"),
     *       @OA\Property(property="txn_ref", type="string", example="ORD20251018093000123"),
     *       @OA\Property(property="amount", type="integer", example=50000),
     *       @OA\Property(property="vnp_transaction_no", type="string", nullable=true),
     *       @OA\Property(property="bank_code", type="string", nullable=true),
     *       @OA\Property(property="card_type", type="string", nullable=true),
     *       @OA\Property(property="message", type="string", example="Thanh toán thành công")
     *     )
     *   ),
     *   @OA\Response(response=404, description="Payment not found")
     * )
     */
    public function status(int $reservationId)
    {
        // Ưu tiên lấy payment PENDING hoặc PAID, nếu không có thì lấy payment mới nhất
        $payment = Payment::where('reservation_id', $reservationId)
            ->whereIn('status', ['PENDING', 'PAID'])
            ->orderByDesc('created_at')
            ->first();

        // Nếu không có PENDING/PAID, lấy payment mới nhất (có thể là FAILED)
        if (!$payment) {
            $payment = Payment::where('reservation_id', $reservationId)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $message = match ($payment->status) {
            'PAID' => 'Thanh toán thành công',
            'FAILED' => 'Thanh toán thất bại',
            default => 'Đang chờ thanh toán',
        };

        return response()->json([
            'status' => $payment->status,
            'reservation_id' => $payment->reservation_id,
            'order_id' => $payment->order_id,
            'txn_ref' => $payment->txn_ref,
            'amount' => $payment->amount,
            'vnp_transaction_no' => $payment->vnp_transaction_no,
            'bank_code' => $payment->bank_code,
            'card_type' => $payment->card_type,
            'message' => $message,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/payments/calculate/{reservation_id}",
     *   operationId="PaymentsCalculate",
     *   tags={"Payments"},
     *   summary="Tính phí đỗ xe cho reservation",
     *   description="Tính phí đỗ xe dựa trên thời gian check-in và check-out thực tế",
     *   @OA\Parameter(
     *     name="reservation_id",
     *     in="path",
     *     required=true,
     *     description="ID của reservation",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Phí đỗ xe đã tính",
     *     @OA\JsonContent(
     *       @OA\Property(property="reservation_id", type="integer", example=101),
     *       @OA\Property(property="amount", type="integer", example=50000),
     *       @OA\Property(property="check_in_at", type="string", format="date-time"),
     *       @OA\Property(property="check_out_at", type="string", format="date-time", nullable=true),
     *       @OA\Property(property="duration_minutes", type="integer", example=120),
     *       @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *       @OA\Property(property="parking_lot_id", type="integer", example=1)
     *     )
     *   ),
     *   @OA\Response(response=404, description="Reservation not found"),
     *   @OA\Response(response=422, description="Reservation chưa check-in hoặc không hợp lệ")
     * )
     */
    public function calculate(int $reservationId)
    {
        $reservation = Reservation::with('slot')->find($reservationId);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }

        // Chỉ tính phí khi đã check-in
        if (!$reservation->check_in_at) {
            return response()->json([
                'message' => 'Reservation chưa check-in, không thể tính phí'
            ], 422);
        }

        $parkingLotId = $reservation->slot->parking_lot_id;
        $vehicleType = $reservation->vehicle_snapshot['vehicle_type'] ?? 'motorbike';
        $checkOutAt = $reservation->check_out_at ?? now();

        $feeService = app(ParkingFeeService::class);
        $amount = $feeService->calculateFee(
            $reservation->check_in_at,
            $checkOutAt,
            $parkingLotId,
            $vehicleType
        );

        $durationMinutes = $reservation->check_in_at->diffInMinutes($checkOutAt);

        return response()->json([
            'reservation_id' => $reservation->id,
            'amount' => $amount,
            'check_in_at' => $reservation->check_in_at->toIso8601String(),
            'check_out_at' => $checkOutAt->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'vehicle_type' => $vehicleType,
            'parking_lot_id' => $parkingLotId,
        ]);
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
}
