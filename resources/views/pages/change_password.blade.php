@extends('layouts.password')
@section('title', 'Change password - Outing System')
@section('content')

    <div class="login-page">
        <div class="login-card">
            <img src="images/logo.png" alt="Outing System" class="login-logo">
            <h1>Change password</h1>
            <p>Set a new password before continuing.</p>

            @if ($error !== '')
                <p class="login-error">{!! htmlspecialchars($error, ENT_QUOTES, 'UTF-8') !!}</p>
            @endif

            <form method="post" action="change_password.php">
                @csrf
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

                <label for="new_password">New password (min {!! PASSWORD_MIN_LENGTH !!} characters)</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="{!! PASSWORD_MIN_LENGTH !!}" maxlength="{!! PASSWORD_MAX_LENGTH !!}" required>

                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>

                <button type="submit">Save password</button>
            </form>

            <p class="login-switch"><a href="logout.php">Log out</a></p>
        </div>
    </div>

@endsection
