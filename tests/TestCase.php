<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs after the app boots but BEFORE RefreshDatabase, so a wrong
     * database is refused before any table is touched.
     */
    protected function setUpTraits()
    {
        $this->assertDatabaseIsSafeForTests();

        return parent::setUpTraits();
    }

    private function assertDatabaseIsSafeForTests(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return;
            }

            throw new RuntimeException("Refusing to run tests on SQLite file '{$database}'. Use :memory:.");
        }

        if (str_ends_with($database, '_test')) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests: database '{$database}' does not end in _test."
        );
    }
}