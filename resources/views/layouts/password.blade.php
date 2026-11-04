<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title')</title><link rel="stylesheet" href="{{ asset('assets/app.css') }}"></head><body>
@if($errors->any())<div class="m-alert m-alert--danger" role="alert">{{ $errors->first() }}</div>@endif
@yield('content')
</body></html>
