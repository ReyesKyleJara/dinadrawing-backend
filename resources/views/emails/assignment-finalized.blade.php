@extends('emails.layout')
@section('title', 'Responsibilities finalized in ' . $planName)
@section('content')
<h1 style="margin:0 0 14px;font-size:24px;">Responsibilities were finalized</h1>
<p style="margin:0 0 12px;color:#555;line-height:1.6;">Hi {{ $userName }}, the responsibility list <strong>{{ $details }}</strong> in <strong>{{ $planName }}</strong> has been finalized.</p>
<p style="margin:0 0 20px;color:#555;line-height:1.6;">Review the final assignments in DiNaDrawing.</p>
@if(!empty($actionUrl))
<a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;background:#f2b73f;color:#171717;text-decoration:none;border-radius:10px;font-weight:800;">View assignments</a>
@endif
@endsection
