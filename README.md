# Laravel Cache Group

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ebects/laravel-cache-group.svg?style=flat-square)](https://packagist.org/packages/ebects/laravel-cache-group)
[![Total Downloads](https://img.shields.io/packagist/dt/ebects/laravel-cache-group.svg?style=flat-square)](https://packagist.org/packages/ebects/laravel-cache-group)
[![PHP Version](https://img.shields.io/packagist/php-v/ebects/laravel-cache-group.svg?style=flat-square)](https://packagist.org/packages/ebects/laravel-cache-group)
[![License](https://img.shields.io/packagist/l/ebects/laravel-cache-group.svg?style=flat-square)](https://packagist.org/packages/ebects/laravel-cache-group)

Group-based cache invalidation for Laravel — organize cache by groups, invalidate by scope (user/role/custom), with automatic Redis Cluster compatibility.

## Features

- 📦 **Cache Groups** — organize cache per feature/module with a dedicated class
- 🎯 **Scoped Invalidation** — per user, role, tenant, or any custom scope
- 🔗 **Cascading Dependencies** — invalidate related groups automatically via `also_invalidate`
- 🛡️ **Redis Cluster Ready** — auto-detect single/cluster mode, zero config
- ⚡ **Throttle/Debounce** — protect against invalidation storms
- 🔐 **Queue Safe** — explicit scope resolution for jobs without auth context
- 🔌 **Pluggable Auth** — works with Sanctum, JWT, Passport, or any custom auth
- 🔍 **Artisan Commands** — inspect, validate, and monitor cache groups
- 🧪 **Testing Utilities** — FakeCacheManager with assertion helpers

## Installation

```bash
composer require ebects/laravel-cache-group
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=cache-group-config
```

## Quick Start

### 1. Create a Cache Group

```php
<?php

namespace App\Cache\Groups;

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\UpdatePost;
use App\Actions\Posts\FetchPosts;
use Ebects\LaravelCacheGroup\Contracts\CacheGroupInterface;

class PostsCacheGroup implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'posts.list';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'user',         // 'global', 'user', 'role', or any custom scope
            'ttl' => 3600,             // 1 hour
            'also_invalidate' => [     // cascade invalidation
                'dashboard.stats',
            ],
        ];
    }

    public static function getClasses(): array
    {
        return [FetchPosts::class];    // classes that READ this cache
    }

    public static function getRemoveClasses(): array
    {
        return [
            CreatePost::class,                           // simple: invalidates this group
            UpdatePost::class => ['posts.detail'],       // also invalidates posts.detail
        ];
    }
}
```

### 2. Register Cache Groups

In a service provider (e.g. `AppServiceProvider`):

```php
use Ebects\LaravelCacheGroup\CacheRegistry;
use App\Cache\Groups\PostsCacheGroup;
use App\Cache\Groups\DashboardCacheGroup;

public function boot(): void
{
    CacheRegistry::registerMany([
        PostsCacheGroup::class,
        DashboardCacheGroup::class,
    ]);
}
```

### 3. Use the Trait in Your Actions

```php
<?php

namespace App\Actions\Posts;

use Ebects\LaravelCacheGroup\Traits\InvalidatesCache;

class CreatePost
{
    use InvalidatesCache;

    public function handle(array $data): Post
    {
        $post = Post::create($data);

        // Auto-detect which caches to invalidate from class mapping
        $this->invalidateCache();

        return $post;
    }
}
```

### 4. Implement ScopeResolver

Create a resolver that tells the library how to identify users/roles in your system:

```php
<?php

namespace App\Cache;

use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;

class AppScopeResolver implements ScopeResolver
{
    public function resolve(string $scope): ?string
    {
        $user = auth()->user();
        if (!$user) return null;

        return match($scope) {
            'user' => (string) $user->id,
            'role' => $user->role->slug,
            'tenant' => (string) $user->tenant_id,
            default => null,
        };
    }

    public function resolveFor(string $scope, mixed $target): ?string
    {
        if (is_string($target)) return $target;

        return match($scope) {
            'user' => (string) $target->id,
            'role' => is_string($target) ? $target : $target->role->slug,
            'tenant' => (string) $target->tenant_id,
            default => null,
        };
    }

    public function hasActiveSession(): bool
    {
        return auth()->check();
    }
}
```

Register in config:

```php
// config/cache-group.php
'scope_resolver' => App\Cache\AppScopeResolver::class,
```

## Usage

### From HTTP Request (Controller/Action)

```php
use Ebects\LaravelCacheGroup\Traits\InvalidatesCache;

class UpdatePost
{
    use InvalidatesCache;

    public function handle(Post $post, array $data): Post
    {
        $post->update($data);

        // Option 1: Auto from class mapping
        $this->invalidateCache();

        // Option 2: Specific prefix
        $this->invalidateCacheByPrefix('posts.list');

        // Option 3: Nuclear — all users
        $this->invalidateAllCache('posts.list');

        return $post;
    }
}
```

### From Queue Job (No Auth Context)

```php
class ProcessImport implements ShouldQueue
{
    use InvalidatesCache;

    public function __construct(
        private string $userId,
    ) {}

    public function handle(): void
    {
        // Must pass explicit target — no auth in queue
        $this->invalidateCacheFor('user', $this->userId);

        // Multiple targets
        $this->invalidateCacheForMany('user', [$userId1, $userId2]);
    }
}
```

### Using Facade

```php
use Ebects\LaravelCacheGroup\Facades\CacheGroup;

CacheGroup::invalidate('posts.list');
CacheGroup::invalidateFor('posts.list', 'user', $userId);
CacheGroup::invalidateAll('master.data');
CacheGroup::forceInvalidate('dashboard.stats');
```

### Force Invalidate with Throttle

```php
$result = $this->forceInvalidateCache('dashboard.stats');

if ($result['throttled']) {
    return response()->json([
        'message' => "Please wait {$result['retry_after']} seconds",
    ], 429);
}
```

Configure throttle per group:

```php
public static function getConfig(): array
{
    return [
        'scope' => 'user',
        'ttl' => 3600,
        'throttle' => [
            'enabled' => true,
            'strategy' => 'throttle', // 'throttle' or 'debounce'
            'window' => 5,            // seconds
        ],
    ];
}
```

## Artisan Commands

```bash
# List all registered cache groups
php artisan cache:groups
php artisan cache:groups --scope=user

# Inspect Redis keys for a cache group (read-only)
php artisan cache:inspect posts.list
php artisan cache:inspect --summary

# Validate configuration & health check
php artisan cache:validate
```

## Redis Cluster Support

The library auto-detects your Redis setup:

| Redis Setup | Strategy | How It Works |
|---|---|---|
| Single Redis | `Cache::tags()->flush()` | Fast, precise |
| Redis Cluster | `SCAN + DEL` per node | Compatible, safe |

No config needed. To force a mode:

```php
// config/cache-group.php
'redis_cluster_mode' => true,  // force cluster
'redis_cluster_mode' => false, // force tags
'redis_cluster_mode' => null,  // auto-detect (default)
```

## Testing

```php
use Ebects\LaravelCacheGroup\CacheManager;
use Ebects\LaravelCacheGroup\Testing\FakeCacheManager;

public function test_creating_post_invalidates_cache()
{
    $fake = new FakeCacheManager();
    $this->app->instance(CacheManager::class, $fake);

    $action = new CreatePost();
    $action->handle(['title' => 'Test']);

    $fake->assertInvalidated('posts.list');
    $fake->assertNotInvalidated('unrelated.prefix');
    $fake->assertInvalidationCount(2);
}
```

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Redis (single or cluster)

## Support

If this package helps you, consider buying me a coffee ☕

[![Saweria](https://img.shields.io/badge/Saweria-FAA918?style=for-the-badge&logo=saweria&logoColor=white)](https://saweria.co/ebects)

## License

MIT License. See [LICENSE](LICENSE) for details.
