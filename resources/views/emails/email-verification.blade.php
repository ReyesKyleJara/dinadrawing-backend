@extends('emails.layout')

@section('title', 'Verify your DiNaDrawing email')

@section('content')
<h1 style="margin:0 0 14px;font-size:24px;line-height:1.25;">Verify your email</h1>
<p style="margin:0 0 18px;color:#555;line-height:1.6;">Hi {{ $userName }}, enter this six-digit code in DiNaDrawing:</p>
<div style="padding:18px;text-align:center;background:#fff8e6;border:1px solid #f2b73f;border-radius:14px;font-size:32px;font-weight:800;letter-spacing:8px;">{{ $verificationCode }}</div>
<p style="margin:18px 0 0;color:#777;font-size:13px;line-height:1.6;">The code expires in 10 minutes. If you did not create this account, you may ignore this email.</p>
@endsection
