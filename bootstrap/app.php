<?php

use App\Http\Middleware\AccountSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['account' => AccountSession::class]);
        $middleware->redirectGuestsTo('/login_student.php');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'current_password', 'new_password', 'confirm_password']);
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->expectsJson() || in_array($r->path(), ['qr_token.php', 'get_notifications.php', 'get_unread_count.php', 'notification_action.php']));
    })->create();
