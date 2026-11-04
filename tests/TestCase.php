<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $db = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if ($app['config']->get('app.env') !== 'testing' || ($db !== ':memory:' && ! str_ends_with((string) $db, '_test'))) {
            throw new \RuntimeException('Tests require APP_ENV=testing and an isolated *_test database or SQLite :memory:.');
        }

return $app;
    }
}
