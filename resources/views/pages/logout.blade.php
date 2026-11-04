@extends('layouts.app')
@section('title','Logout - Outing System')
@section('content')
<main><h1>Logout</h1><form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form></main>
@endsection
