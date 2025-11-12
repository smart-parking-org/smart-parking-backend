<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 28px;
            color: {{ $color }};
            margin-bottom: 15px;
        }

        p {
            color: #6b7280;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .button {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 15px 40px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            transition: 0.3s;
            cursor: pointer;
            border: none;
        }

        .button:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .countdown {
            margin-top: 20px;
            color: #9ca3af;
            font-size: 14px;
        }

        .info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #9ca3af;
        }

        .fallback {
            margin-top: 20px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 10px;
            font-size: 14px;
            color: #6b7280;
            display: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="icon">{{ $icon }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        <button onclick="openApp()" class="button" id="redirectBtn">
            Quay lại App
        </button>

        <div class="countdown">
            Tự động quay lại sau <span id="countdown">5</span> giây...
        </div>

        <div class="info">
            <p>Mã đơn: {{ $payment->order_id }}</p>
            <p>Mã giao dịch: {{ $payment->txn_ref }}</p>
        </div>

        <div class="fallback" id="fallback">
            <p>Nếu app không tự động mở, vui lòng nhấn nút "Quay lại App" ở trên hoặc đóng trình duyệt và mở app thủ công.</p>
        </div>
    </div>

    <script>
        const status = '{{ $payment->status }}';
        const txnRef = '{{ $payment->txn_ref }}';
        const orderId = '{{ $payment->order_id }}';
        const reservationId = '{{ $payment->reservation_id ?? '' }}';
        
        // ✅ Tạo deep link thông thường
        const deepLink = `smartparking://payment/result?status=${status}&txn_ref=${txnRef}&order_id=${orderId}&reservation_id=${reservationId}`;
        
        // ✅ Tạo Intent URL cho Android
        const intentUrl = `intent://payment/result?status=${status}&txn_ref=${txnRef}&order_id=${orderId}&reservation_id=${reservationId}#Intent;scheme=smartparking;package=com.hoangkhadev.smartparkingmobile;end`;
        
        // ✅ Detect Android
        const isAndroid = /android/i.test(navigator.userAgent || navigator.vendor || window.opera);
        
        let countdown = 5;
        const countdownEl = document.getElementById('countdown');
        const btn = document.getElementById('redirectBtn');
        const fallback = document.getElementById('fallback');
        let appOpened = false;
        let redirectAttempted = false;

        function openApp() {
            if (redirectAttempted) return;
            redirectAttempted = true;
            appOpened = true;
            clearInterval(timer);
            
            // ✅ Android: dùng Intent URL
            if (isAndroid) {
                window.location.href = intentUrl;
            } else {
                // ✅ iOS: dùng deep link thông thường
                window.location.replace(deepLink);
            }
            
            // ✅ Hiển thị fallback sau 2 giây nếu vẫn còn ở trang
            setTimeout(function() {
                if (document.hasFocus()) {
                    fallback.style.display = 'block';
                }
            }, 2000);
        }

        // ✅ Auto redirect sau 5 giây
        const timer = setInterval(function () {
            countdown--;
            if (countdownEl) {
                countdownEl.textContent = countdown;
            }
            if (countdown <= 0) {
                clearInterval(timer);
                if (!appOpened) {
                    openApp();
                }
            }
        }, 1000);

        // ✅ Thử mở app ngay lập tức
        setTimeout(function() {
            if (!appOpened) {
                openApp();
            }
        }, 500);

        // ✅ Detect khi user quay lại browser (app đã mở thành công)
        let hidden = false;
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                hidden = true;
            } else if (hidden) {
                // User quay lại = app đã mở thành công
                clearInterval(timer);
                if (countdownEl) {
                    countdownEl.textContent = '0';
                }
            }
        });

        // ✅ Fallback: thử lại với deep link thông thường nếu là Android và intent không hoạt động
        if (isAndroid) {
            setTimeout(function() {
                if (!appOpened && document.hasFocus()) {
                    // Thử lại với deep link thông thường
                    window.location.href = deepLink;
                }
            }, 1500);
        }
    </script>
</body>

</html>