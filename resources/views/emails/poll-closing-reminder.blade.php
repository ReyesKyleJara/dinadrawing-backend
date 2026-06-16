@extends('emails.layout')
@section('title', 'Poll closing soon in ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">A poll is closing soon</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, the poll <strong>“{{ $pollQuestion }}”</strong> in <strong>{{ $planName }}</strong> closes in {{ $timeRemaining }}.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Cast your vote before the poll ends.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">Vote now</a>
@endif
@endsection
