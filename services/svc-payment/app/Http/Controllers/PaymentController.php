<?php

namespace App\Http\Controllers;

use App\Models\MonthlyPass;
use App\Models\Payment;
use App\Models\Violation;
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
                }
            }
        }

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
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
