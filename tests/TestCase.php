<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * BENTENG: paksa koneksi test ke SQLite :memory: + driver array, apa pun isi
     * config cache (bootstrap/cache/config.php). Tanpa ini, `php artisan test` yang
     * dijalankan TANPA config:clear memakai config ter-cache (mysql) → RefreshDatabase
     * me-migrate:fresh DB MySQL dev (TERHAPUS). force="true" di phpunit.xml saja tak cukup
     * karena config sudah ter-cache (config() membaca cache, bukan env).
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('broadcasting.default', 'null');

        return $app;
    }
}
