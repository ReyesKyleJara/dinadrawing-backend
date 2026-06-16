@extends('emails.layout')
@section('title', 'Incomplete contribution in ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">Your contribution is still empty</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, your entry for <strong>{{ $contributionType }}</strong> in <strong>{{ $planName }}</strong> has not been filled in yet.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Open the plan and add what you will bring, share, or do.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">Update contribution</a>
@endif
@endsection
