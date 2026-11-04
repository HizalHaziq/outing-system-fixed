<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordRequest;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function home(Request $r)
    {
        return redirect()->route(Auth::guard('staff')->check() ? (Auth::guard('staff')->user()->role === 'guard' ? 'gate' : 'dashboard') : (Auth::guard('student')->check() ? 'student_dashboard' : 'login_student'));
    }

    public function form(Request $r)
    {
        return view('pages.'.$r->route('page'), ['error' => $r->query('expired') ? 'Your session expired. Please sign in again.' : '']);
    }

    public function login(LoginRequest $r)
    {
        $staff = $r->routeIs('staff.login');
        $guard = $staff ? 'staff' : 'student';
        $field = $staff ? 'staff_id' : 'std_no';
        $id = trim($r->input($field));
        $key = 'login:'.$guard.':'.hash('sha256', mb_strtolower($id));
        $ip = 'login-ip:'.hash('sha256', $r->ip());
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ip, 20)) {
            throw ValidationException::withMessages([$field => 'Too many failed attempts. Please try again in 15 minutes.']);
        }
        $user = ($staff ? Staff::query() : Student::query())->where($field, $id)->first();
        $ok = password_verify($r->input('password'), $user?->password_hash ?? '$2y$10$Uevc9hWZ4qIpzwSDpAylgupJJAmG5HShssB5HU6GbRMCHVI6lLRKW');
        if (! $user || ! $ok || ! $user->is_active) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ip, 900);
            throw ValidationException::withMessages([$field => 'Invalid identifier or password.']);
        }
        RateLimiter::clear($key);
        if (Hash::needsRehash($user->password_hash)) {
            $user->password_hash = Hash::make($r->input('password'));
            $user->save();
        }
        Auth::guard('student')->logout();
        Auth::guard('staff')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        Auth::guard($guard)->login($user);
        $r->session()->regenerate();
        $r->session()->put(['login_at' => now()->timestamp, 'last_activity' => now()->timestamp, 'password_version' => hash('sha256', $user->password_hash)]);

        return redirect()->route($user->must_change_password ? 'change_password' : ($staff ? ($user->role === 'guard' ? 'gate' : 'dashboard') : 'student_dashboard'));
    }

    public function passwordForm()
    {
        return view('pages.change_password', ['error' => '']);
    }

    public function password(PasswordRequest $r)
    {
        $u = $r->user();
        if (! password_verify($r->current_password, $u->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        $u->update(['password_hash' => Hash::make($r->new_password), 'must_change_password' => 0]);
        $r->session()->regenerate();
        $r->session()->regenerateToken();
        $r->session()->put('password_version', hash('sha256', $u->password_hash));

        return redirect()->route($u instanceof Student ? 'student_dashboard' : ($u->role === 'guard' ? 'gate' : 'dashboard'))->with('flash', ['type' => 'success', 'message' => 'Password changed.']);
    }

    public function logout(Request $r)
    {
        Auth::guard('staff')->logout();
        Auth::guard('student')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login_student');
    }
}
