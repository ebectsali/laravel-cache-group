<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Groups
    |--------------------------------------------------------------------------
    |
    | Register your cache group classes here. Each class must implement
    | Ebects\LaravelCacheGroup\Contracts\CacheGroupInterface.
    |
    | Example:
    |   'groups' => [
    |       App\Cache\Groups\StatistikCacheGroup::class,
    |       App\Cache\Groups\InboxCacheGroup::class,
    |   ],
    |
    */
    'groups' => [],

    /*
    |--------------------------------------------------------------------------
    | Scope Resolver
    |--------------------------------------------------------------------------
    |
    | The class responsible for resolving scope identifiers (user, role, tenant).
    | Must implement Ebects\LaravelCacheGroup\Contracts\ScopeResolver.
    |
    | The default resolver works with Laravel's built-in auth system.
    | Override this for JWT, Sanctum, Passport, or custom auth.
    |
    */
    'scope_resolver' => Ebects\LaravelCacheGroup\DefaultScopeResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Invalidation Strategy
    |--------------------------------------------------------------------------
    |
    | How cache invalidation is performed.
    |
    | Options:
    |   - HybridStrategy::class   (default) Auto-detect single/cluster
    |   - TagStrategy::class      Force tag-based (single Redis only)
    |   - ClusterScanStrategy::class  Force SCAN+DEL (cluster only)
    |
    */
    'strategy' => Ebects\LaravelCacheGroup\Strategies\HybridStrategy::class,

    /*
    |--------------------------------------------------------------------------
    | Default TTL
    |--------------------------------------------------------------------------
    |
    | Default cache TTL in seconds when not specified by the cache group.
    |
    */
    'default_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Redis Cluster Mode
    |--------------------------------------------------------------------------
    |
    | Force Redis Cluster mode detection.
    | Set to true/false to override auto-detection, or null for auto-detect.
    |
    */
    'redis_cluster_mode' => env('CACHE_GROUP_CLUSTER_MODE', null),

    /*
    |--------------------------------------------------------------------------
    | Missing Context Behavior
    |--------------------------------------------------------------------------
    |
    | What happens when invalidateCache() is called without an active
    | auth session (e.g. in a queue job) for a scoped cache group.
    |
    | Options:
    |   - 'throw'          Throw MissingScopeContextException (default)
    |   - 'skip'           Silently skip invalidation
    |   - 'invalidate_all' Invalidate all entries for the prefix
    |
    */
    'on_missing_context' => 'throw',

    /*
    |--------------------------------------------------------------------------
    | Redis Cluster Configuration
    |--------------------------------------------------------------------------
    |
    | Advanced settings for Redis Cluster SCAN operations.
    |
    */
    'cluster' => [
        // Override cluster hosts (leave empty to auto-detect from database.redis config)
        'hosts' => [],

        // Connection timeout in seconds
        'timeout' => 5,

        // Read/write timeout in seconds
        'read_write_timeout' => 10,

        // Maximum SCAN iterations per node
        'max_scan_iterations' => 100,

        // SCAN COUNT hint per iteration
        'scan_count' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-generated Configuration (DO NOT EDIT)
    |--------------------------------------------------------------------------
    |
    | These are populated at runtime by the ServiceProvider.
    | They are used internally by the InvalidatesCache trait.
    |
    */
    'class_mapping' => [],
    'routes' => [],

];
