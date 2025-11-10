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
            color:
                {{ $color }}
            ;
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
    </style>
</head>

<body>
    <div class="container">
        <div class="icon">{{ $icon }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</particular>

            <a href="{{ $deepLink }}" class="button" id="redirectBtn">
                Quay lại App
            </a>

        <div class="countdown">
            Tự động quay lại sau <span id="countdown">3</span> giây...
        </div>

        <div class="info">
            <p>Mã đơn: {{ $payment->order_id }}</p>
            <p>Mã giao dịch: {{ $payment->txn_ref }}</p>
        </div>
    </div>

    <script>
        let countdown = 3;
        const countdownEl = document.getElementById('countdown');
        const btn = document.getElementById('redirectBtn');

        const timer = setInterval(function () {
            countdown--;
            if (countdownEl) {
                countdownEl.textContent = countdown;
            }
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = '{{ $deepLink }}';
            }
        }, 1000);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            clearInterval(timer);
            window.location.href = '{{ $deepLink }}';
        });

        // Auto redirect after 100ms
        setTimeout(function () {
            window.location.href = '{{ $deepLink }}';
        }, 100);
    </script>
</body>

</html>