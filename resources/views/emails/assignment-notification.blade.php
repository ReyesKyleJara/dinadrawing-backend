@extends('emails.layout')
@section('title', 'New responsibility in ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">You have a new responsibility</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, you were assigned to <strong>{{ $taskDescription }}</strong> in <strong>{{ $planName }}</strong>.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Open DiNaDrawing to review the assignment and respond when needed.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">Review assignment</a>
@endif
@endsection
