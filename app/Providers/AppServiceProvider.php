<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        View::composer(['layouts.*', 'partials.*'], function ($view) {
            $view->with('account', profile_account());
        });
        // Configuration omissions fail closed before any production connection is attempted.
        if ($this->app->environment('production') && in_array(config('database.default'), ['mysql', 'mariadb'])) {
            $connection = config('database.connections.'.config('database.default'));
            if (empty($connection['database']) || empty($connection['username']) || empty($connection['password']) || strtolower($connection['username']) === 'root') {
                throw new \RuntimeException('Set explicit non-root database credentials before production startup.');
            }
        }
    }
}
