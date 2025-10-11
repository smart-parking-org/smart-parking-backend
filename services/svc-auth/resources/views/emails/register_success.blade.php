@extends('emails.layout')
@section('title', 'Xác nhận đăng ký')
@section('content')
    <h2>Xin chào, {{ $user->name ?? "bạn" }}</h2>
    <p>Bạn đã đăng ký tài khoản thành công trên hệ thống Smart Parking.</p>
    <p>Vui lòng chờ admin duyệt tài khoản trước khi sử dụng dịch vụ.</p>
    <p>Cảm ơn bạn đã tin tưởng sử dụng Smart Parking.</p>
@endsection
