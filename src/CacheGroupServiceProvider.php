<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Commands\CacheGroupsCommand;
use Ebects\LaravelCacheGroup\Commands\CacheInspectCommand;
use Ebects\LaravelCacheGroup\Commands\CacheValidateCommand;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Contracts\VariantResolver;
use Ebects\LaravelCacheGroup\Strategies\HybridStrategy;
use Illuminate\Support\ServiceProvider;

class CacheGroupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cache-group.php',
            'cache-group'
        );

        $this->app->singleton(ScopeResolver::class, function ($app) {
            $resolverClass = config('cache-group.scope_resolver');

            if ($resolverClass && class_exists($resolverClass)) {
                return $app->make($resolverClass);
            }

            return new DefaultScopeResolver();
        });

        $this->app->singleton(VariantResolver::class, function ($app) {
            $resolverClass = config('cache-group.variant_resolver');

            if ($resolverClass && class_exists($resolverClass)) {
                return $app->make($resolverClass);
            }

            return new RequestVariantResolver();
        });

        $this->app->singleton(InvalidationStrategy::class, function () {
            return new HybridStrategy();
        });

        $this->app->singleton(HybridStrategy::class, function ($app) {
            return $app->make(InvalidationStrategy::class);
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(
                $app->make(ScopeResolver::class),
                $app->make(InvalidationStrategy::class),
            );
        });
    }

    public function boot(): void
    {
        // Register class-based groups
        $groups = config('cache-group.groups', []);
        if (! empty($groups)) {
            CacheRegistry::registerMany($groups);
        }

        // Register flat prefix configs (for apps with their own CacheGroup format)
        $flatConfigs = config('cache-group.prefix_configs', []);
        if (! empty($flatConfigs)) {
            CacheRegistry::registerFlatConfigs($flatConfigs);
        }

        // Register flat class mapping (Action class → prefixes to invalidate)
        $flatClassMapping = config('cache-group.class_mapping', []);
        if (! empty($flatClassMapping)) {
            CacheRegistry::registerFlatClassMapping($flatClassMapping);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/cache-group.php' => config_path('cache-group.php'),
            ], 'cache-group-config');

            $this->commands([
                CacheGroupsCommand::class,
                CacheInspectCommand::class,
                CacheValidateCommand::class,
            ]);
        }
    }
}
