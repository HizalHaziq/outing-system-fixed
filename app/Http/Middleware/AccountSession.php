<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountSession
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $guard = Auth::guard('staff')->check() ? 'staff' : 'student';
        Auth::shouldUse($guard);
        $user = Auth::user();
        if (! $user) {
            return redirect()->route(in_array('student', $roles, true) ? 'login_student' : 'login_staff');
        }
        $user->refresh();
        $now = now()->timestamp;
        $start = $request->session()->get('login_at', $now);
        $last = $request->session()->get('last_activity', $now);
        $idle = $user->roleName() === 'guard' ? 3600 : 1800;
        $version = $request->session()->get('password_version', hash('sha256', $user->password_hash));
        if (! $user->is_active || $now - $last >= $idle || $now - $start >= 28800 || ! hash_equals($version, hash('sha256', $user->password_hash))) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($guard === 'student' ? 'login_student' : 'login_staff', ['expired' => 1]);
        }
        $activity = $request->routeIs('qr_token', 'get_notifications', 'get_unread_count', 'notifications.action') ? $last : $now;
        $request->session()->put(['last_activity' => $activity, 'login_at' => $start]);
        if ($roles && ! in_array($user->roleName(), $roles, true)) {
            abort(403);
        }
        if ($user->must_change_password && ! $request->routeIs('change_password', 'password.update', 'logout', 'logout.confirm')) {
            return redirect()->route('change_password');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control','no-store, private');

        return $response;
    }
}
