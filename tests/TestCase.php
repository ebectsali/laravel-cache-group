<?php

namespace Ebects\LaravelCacheGroup\Tests;

use Ebects\LaravelCacheGroup\CacheGroupServiceProvider;
use Ebects\LaravelCacheGroup\CacheRegistry;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CacheRegistry::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CacheGroupServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.prefix', 'test_cache');
        $app['config']->set('cache-group.debug', false);
        $app['config']->set('cache-group.redis_cluster_mode', false);
    }
}
