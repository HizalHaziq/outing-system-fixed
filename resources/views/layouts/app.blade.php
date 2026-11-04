<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Outing System')</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}">
<link rel="stylesheet" href="{{ asset('assets/laravel-fixes.css') }}">
@stack('styles')
</head>
<body>
@include('partials.header')
@include($account['user_type']==='student'?'partials.student_menu':'partials.mainmenu')
@if($errors->any())
<div class="m-alert m-alert--danger validation-errors" role="alert"><ul>
@foreach($errors->all() as $message)<li>{{ $message }}</li>
@endforeach</ul></div>
@endif
@yield('content')
@include('partials.footer')
@stack('scripts')
</body>
</html>
