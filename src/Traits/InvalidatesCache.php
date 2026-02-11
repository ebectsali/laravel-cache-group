<?php

namespace Ebects\LaravelCacheGroup\Traits;

use Ebects\LaravelCacheGroup\CacheManager;
use Ebects\LaravelCacheGroup\CacheRegistry;

/**
 * InvalidatesCache Trait
 *
 * Use this trait in your Action/Service classes to enable automatic cache invalidation.
 *
 * @example
 * class CreatePost {
 *     use InvalidatesCache;
 *
 *     public function handle($data) {
 *         // ... create post
 *
 *         // Option 1: Auto-detect from class mapping (HTTP context)
 *         $this->invalidateCache();
 *
 *         // Option 2: Explicit scope + target (queue safe)
 *         $this->invalidateCacheFor('user', $userId);
 *
 *         // Option 3: Invalidate specific prefix
 *         $this->invalidateCacheByPrefix('posts.list');
 *
 *         // Option 4: Force with throttle
 *         $this->forceInvalidateCache('posts.list');
 *     }
 * }
 */
trait InvalidatesCache
{
    /**
     * Invalidate cache based on class mapping (uses current auth context).
     *
     * Looks up which cache groups this class should invalidate
     * and performs invalidation with cascading dependencies.
     *
     * @return int Total keys deleted
     *
     * @throws \Ebects\LaravelCacheGroup\Exceptions\MissingScopeContextException
     */
    protected function invalidateCache(): int
    {
        return $this->getCacheManager()->invalidateByClass(static::class);
    }

    /**
     * Invalidate cache with explicit scope and target.
     *
     * Queue-safe — does not require auth context.
     *
     * @param string $scope Scope name ('user', 'role', 'tenant', etc.)
     * @param mixed $target The target to resolve (User model, string ID, etc.)
     * @return int Total keys deleted
     */
    protected function invalidateCacheFor(string $scope, mixed $target): int
    {
        return $this->getCacheManager()->invalidateByClassFor(static::class, $scope, $target);
    }

    /**
     * Invalidate a specific cache prefix.
     *
     * @param string $prefix Cache group prefix
     * @return int Keys deleted
     */
    protected function invalidateCacheByPrefix(string $prefix): int
    {
        $manager = $this->getCacheManager();
        $deleted = $manager->invalidate($prefix);
        $deleted += $manager->invalidateRelated($prefix);

        return $deleted;
    }

    /**
     * Invalidate a specific cache prefix with explicit target.
     *
     * @param string $prefix Cache group prefix
     * @param string $scope Scope name
     * @param mixed $target Target to resolve
     * @return int Keys deleted
     */
    protected function invalidateCachePrefixFor(string $prefix, string $scope, mixed $target): int
    {
        $manager = $this->getCacheManager();
        $deleted = $manager->invalidateFor($prefix, $scope, $target);
        $deleted += $manager->invalidateRelatedFor($prefix, $scope, $target);

        return $deleted;
    }

    /**
     * Invalidate ALL cache for a prefix (all users/roles/scopes).
     *
     * Nuclear option — use carefully.
     *
     * @param string $prefix Cache group prefix
     * @return int Keys deleted
     */
    protected function invalidateAllCache(string $prefix): int
    {
        return $this->getCacheManager()->invalidateAll($prefix);
    }

    /**
     * Invalidate cache for multiple targets.
     *
     * @param string $scope Scope name
     * @param array $targets List of targets
     * @return int Total keys deleted
     */
    protected function invalidateCacheForMany(string $scope, array $targets): int
    {
        $totalDeleted = 0;

        foreach ($targets as $target) {
            $totalDeleted += $this->invalidateCacheFor($scope, $target);
        }

        return $totalDeleted;
    }

    /**
     * Force invalidate with throttle protection.
     *
     * @param string|null $prefix Specific prefix, or null to use class mapping
     * @return array{invalidated: bool, throttled: bool, retry_after: int|null}
     */
    protected function forceInvalidateCache(?string $prefix = null): array
    {
        $manager = $this->getCacheManager();

        if ($prefix !== null) {
            return $manager->forceInvalidate($prefix);
        }

        // Use class mapping
        $prefixes = CacheRegistry::getPrefixesForClass(static::class);
        $results = [];

        foreach ($prefixes as $p) {
            $results[] = $manager->forceInvalidate($p);
        }

        // Aggregate results
        $anyInvalidated = collect($results)->contains('invalidated', true);
        $anyThrottled = collect($results)->contains('throttled', true);
        $maxRetry = collect($results)->max('retry_after');

        return [
            'invalidated' => $anyInvalidated,
            'throttled' => $anyThrottled,
            'retry_after' => $maxRetry,
        ];
    }

    /**
     * Get the CacheManager instance.
     *
     * @return CacheManager
     */
    protected function getCacheManager(): CacheManager
    {
        return app(CacheManager::class);
    }
}
