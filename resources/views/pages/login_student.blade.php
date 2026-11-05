@extends('layouts.login')
@section('title', 'Student login - Outing System')
@section('content')

    <div id="lg-particles"></div>

    <div class="lg-wrap">
        @include('partials.login-video')

        <div class="lg-card">

            <!-- TEST ONLY: login toggle. Remove this whole block before go-live. -->
            <div style="display:flex;border-bottom:1px solid #e2dbe9;font-size:14px;font-weight:600;">
                <a href="login_student.php" style="flex:1;text-align:center;padding:12px;text-decoration:none;background:#5b1a8b;color:#fff;">Student</a>
                <a href="login_staff.php" style="flex:1;text-align:center;padding:12px;text-decoration:none;background:#f3edf7;color:#5b1a8b;">Staff</a>
            </div>
            <!-- /TEST ONLY -->

            <div class="lg-body">
                @if($errors->any())<p class="lg-error" role="alert">{{ $errors->first() }}</p>
@endif
<h1>Student login</h1>

                @if ($error !== '')
                    <p class="lg-error">{!! htmlspecialchars($error, ENT_QUOTES, 'UTF-8') !!}</p>
                @endif

                <form method="post" action="login_student.php">
                    @csrf
                    <label for="std_no">Student No.</label>
                    <input type="text" id="std_no" name="std_no" autocomplete="username" required>

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>

                    <button type="submit">Sign in</button>
                </form>
            </div>

            <div class="lg-foot">&copy; {!! date('Y') !!} Outing System</div>
        </div>
    </div>

    
    

@endsection
