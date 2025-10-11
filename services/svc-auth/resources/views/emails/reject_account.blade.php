@extends('emails.layout')
@section('title', 'Tài khoản đã bị từ chối')
@section('content')
    <h2>Xin chào, {{ $user->name ?? "bạn" }}</h2>
    <p>Rất tiếc, tài khoản Smart Parking của bạn đã <strong>bị từ chối</strong>.</p>
    @isset($reason)
        <p><strong>Lý do:</strong> {{ $reason }}</p>
    @endisset
    <p>Nếu cần điều chỉnh thông tin hoặc muốn được xem xét lại, vui lòng <strong>liên hệ quản trị viên</strong>.</p>
    <p>Cảm ơn bạn đã quan tâm tới Smart Parking.</p>
@endsection
