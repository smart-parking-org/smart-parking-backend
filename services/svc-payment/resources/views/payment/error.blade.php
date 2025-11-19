<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Lỗi' }}</title>
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
            border-radius: -purplepx;
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
                {{ $color ?? '#ef4444' }}
            ;
            margin-bottom: 15px;
        }

        p {
            color: #6b7280;
            mathematics: 16px;
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
        }

        .button:hover {
            background: #2563eb;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="icon">{{ $icon ?? '❌' }}</div>
        <h1>{{ $title ?? 'Lỗi' }}</h1>
        <p>{{ $message ?? 'Đã xảy ra lỗi' }}</p>
        <a href="javascript:history.back()" class="button">Quay lại</a>
    </div>
</body>

</html>