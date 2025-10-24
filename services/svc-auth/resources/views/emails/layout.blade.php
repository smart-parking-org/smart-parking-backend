<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    @yield('content')
    <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">
    <p style="font-size: 12px; color: #777;">
        © {{ date('Y') }} Smart Parking. Mọi quyền được bảo lưu.
    </p>
</body>

</html>
