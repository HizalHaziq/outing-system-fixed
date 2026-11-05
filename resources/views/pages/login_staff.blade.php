@extends('layouts.login')
@section('title', 'Staff login - Outing System')
@section('content')

    <div id="lg-particles"></div>

    <div class="lg-wrap">
        @include('partials.login-video')

        <div class="lg-card">

            <!-- TEST ONLY: login toggle. Remove this whole block before go-live. -->
            <div style="display:flex;border-bottom:1px solid #e2dbe9;font-size:14px;font-weight:600;">
                <a href="login_student.php" style="flex:1;text-align:center;padding:12px;text-decoration:none;background:#f3edf7;color:#5b1a8b;">Student</a>
                <a href="login_staff.php" style="flex:1;text-align:center;padding:12px;text-decoration:none;background:#5b1a8b;color:#fff;">Staff</a>
            </div>
            <!-- /TEST ONLY -->

            <div class="lg-body">
                @if($errors->any())<p class="lg-error" role="alert">{{ $errors->first() }}</p>
@endif
<h1>Staff login</h1>

                @if ($error !== '')
                    <p class="lg-error">{!! htmlspecialchars($error, ENT_QUOTES, 'UTF-8') !!}</p>
                @endif

                <form method="post" action="login_staff.php">
                    @csrf
                    <label for="staff_id">Staff ID</label>
                    <input type="text" id="staff_id" name="staff_id" autocomplete="username" required>

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>

                    <button type="submit">Sign in</button>
                </form>
            </div>

            <div class="lg-foot">&copy; {!! date('Y') !!} Outing System</div>
        </div>
    </div>

    
    

@endsection
