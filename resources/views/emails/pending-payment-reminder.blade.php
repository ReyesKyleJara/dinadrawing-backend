@extends('emails.layout')
@section('title', 'Payment pending in ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">Contribution still pending</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, your contribution of <strong>{{ $amount }}</strong> for <strong>{{ $planName }}</strong> is still marked unpaid.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Target date: <strong>{{ $dueDate }}</strong>.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">Review payment</a>
@endif
@endsection
