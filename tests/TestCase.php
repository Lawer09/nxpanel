<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the Laravel application for feature tests.
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $this->guardAgainstUnsafeDatabase($app);

        return $app;
    }

    /**
     * Stop destructive feature tests from running against production or persistent databases.
     */
    private function guardAgainstUnsafeDatabase($app): void
    {
        if ($app->environment('production')) {
            throw new RuntimeException('Refusing to run tests with APP_ENV=production.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException('Refusing to run tests outside the sqlite :memory: database.');
        }
    }
}
