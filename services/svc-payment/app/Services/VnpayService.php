<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Str;

class VnpayService
{
    private string $tmnCode;
    private string $hashSecret;
    private string $endpoint;
    private string $returnUrl;
    private string $ipnUrl;

    public function __construct()
    {
        $this->tmnCode = config('services.vnpay.tmn_code');
        $this->hashSecret = config('services.vnpay.hash_secret');
        $this->endpoint = config('services.vnpay.endpoint');
        $this->returnUrl = config('services.vnpay.return_url');
        $this->ipnUrl = config('services.vnpay.ipn_url');
    }

    /** Tạo URL thanh toán VNPay */
    public function createPaymentUrl(array $data): string
    {
        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => $this->tmnCode,
            'vnp_Amount' => ((int) $data['amount']) * 100, // ×100 theo yêu cầu VNPay
            'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => $data['txn_ref'],
            'vnp_OrderInfo' => 'Thanh toan don ' . ($data['order_id'] ?? $data['txn_ref']),
            'vnp_OrderType' => 'other',
            'vnp_Locale' => 'vn',
            'vnp_ReturnUrl' => $this->returnUrl,
            'vnp_IpAddr' => request()->ip(),
            'vnp_CreateDate' => now()->format('YmdHis'),
        ];

        if (!empty($data['bank_code'])) {
            $params['vnp_BankCode'] = $data['bank_code'];
        }

        // 1) Sắp xếp key theo alpha
        ksort($params);

        // 2) Build chuỗi ký theo RFC1738 (mặc định), KHÔNG urldecode
        $hashData = http_build_query($params, '', '&');

        // 3) Ký HMAC SHA512
        $secure = hash_hmac('sha512', $hashData, $this->hashSecret);

        // (tuỳ chọn) Khai báo kiểu hash ra query, KHÔNG đưa vào chuỗi ký
        // $params['vnp_SecureHashType'] = 'SHA512';

        // 4) Ghép URL giữ nguyên encoding như khi ký
        $url = $this->endpoint . '?' . $hashData . '&vnp_SecureHash=' . $secure;

        Log::info('VNPAY CREATE', [
            'tmn' => $this->tmnCode,
            'endpoint' => $this->endpoint,
            'return' => $this->returnUrl,
            'ipn' => $this->ipnUrl,
            'hashData' => $hashData,
            'secure' => $secure,
            'url' => $url,
        ]);

        return $url;
    }


    /** Verify chữ ký VNPay (Return/IPN) */
    public function verify(array $input): bool
    {
        $received = $input['vnp_SecureHash'] ?? '';

        // 1) Bỏ field hash khỏi mảng trước khi ký lại
        unset($input['vnp_SecureHash'], $input['vnp_SecureHashType']);

        // 2) Sắp xếp key theo alpha
        ksort($input);

        // 3) Build chuỗi ký theo *đúng* RFC1738 (giống bên create)
        $hashData = http_build_query($input, '', '&');

        // 4) Ký lại và so sánh an toàn
        $calc = hash_hmac('sha512', $hashData, $this->hashSecret);
        $ok = hash_equals($calc, $received);

        if (!$ok) {
            Log::warning('VNPAY VERIFY FAIL', [
                'hashData' => $hashData,
                'calc' => $calc,
                'received' => $received,
            ]);
        }

        return $ok;
    }

}
