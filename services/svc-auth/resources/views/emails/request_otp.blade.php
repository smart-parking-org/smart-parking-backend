@extends('emails.layout')

@section('title', 'Mã OTP khôi phục mật khẩu')

@section('content')
    <h2>Xin chào, {{ $user->name ?? "bạn" }}</h2>
    <p>Mã OTP để đặt lại mật khẩu của bạn là: <strong>{{ $otp }}</strong></p>
    <p>Mã này có hiệu lực trong 5 phút.</p>
@endsection
