@extends('emails.layout')
@section('title', 'Tài khoản đã được duyệt')
@section('content')
    <h2>Xin chào, {{ $user->name ?? "bạn" }}</h2>
    <p>Tài khoản Smart Parking của bạn đã được <strong>duyệt</strong>.</p>
    <p>Bạn có thể đăng nhập và sử dụng dịch vụ ngay.</p>
    <p>Nếu bạn không thực hiện yêu cầu này, vui lòng liên hệ hỗ trợ.</p>
    <p>Cảm ơn bạn đã tin tưởng sử dụng Smart Parking.</p>
@endsection
