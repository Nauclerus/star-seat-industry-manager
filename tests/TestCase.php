<?php

namespace IndustryManager\Tests;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case.
 *
 * The plugin migrations are run directly so tests exercise the real schema
 * without booting SeAT's service provider (which needs the wider SeAT app).
 * Core SDE tables the plugin only reads are created as minimal stubs.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runPluginMigrations();
        $this->createCoreSdeStubs();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Keep caches from leaking between tests, and give each test its own
        // storage so a downloaded/extracted SDE never crosses tests.
        $app['config']->set('cache.default', 'array');
        $app->useStoragePath(sys_get_temp_dir() . '/im-tests-' . uniqid());
    }

    protected function runPluginMigrations(): void
    {
        foreach (glob(__DIR__ . '/../src/Database/migrations/*.php') as $file) {
            $migration = require $file;
            $migration->up();
        }
    }

    /**
     * Minimal invTypes / invGroups / invCategories so the recipe joins resolve.
     * These are SeAT-core tables; the plugin never writes to them.
     */
    protected function createCoreSdeStubs(): void
    {
        if (! Schema::hasTable('invTypes')) {
            Schema::create('invTypes', function ($table) {
                $table->integer('typeID');
                $table->string('typeName')->nullable();
                $table->integer('groupID')->nullable();
                $table->integer('published')->nullable();
            });
        }

        if (! Schema::hasTable('invGroups')) {
            Schema::create('invGroups', function ($table) {
                $table->integer('groupID');
                $table->integer('categoryID')->nullable();
                $table->string('groupName')->nullable();
            });
        }

        if (! Schema::hasTable('invCategories')) {
            Schema::create('invCategories', function ($table) {
                $table->integer('categoryID');
                $table->string('categoryName')->nullable();
            });
        }
    }
}
