@extends('emails.layout')
@section('title', 'Upcoming plan: ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">Your plan is coming up</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, <strong>{{ $planName }}</strong> is scheduled for <strong>{{ $dateTime }}</strong>.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Open the plan to review the schedule, location, responsibilities, and budget.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">View plan</a>
@endif
@endsection
