<?php

namespace Ebects\LaravelCacheGroup\Strategies;

use Ebects\LaravelCacheGroup\CacheKeyBuilder;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TagInvalidationStrategy implements InvalidationStrategy
{
    /**
     * Invalidate cache for a specific prefix + scope.
     *
     * IMPORTANT: Laravel's Cache::tags(['a', 'b'])->flush() does a UNION flush —
     * it deletes ALL entries from BOTH tag sets. This means using composite tags
     * for flush would be overly aggressive (nuclear flush).
     *
     * Strategy:
     *   - Global: flush by prefix tag → all entries for this prefix
     *   - Scoped (peruser/perrole): flush by scope tag → all entries for this user/role
     *     This matches Nadine's original behavior and is safe.
     */
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        try {
            if ($scope === 'global' || $identifier === null) {
                // Global: flush everything under this prefix
                Cache::tags([$prefix])->flush();
                $this->debug('Global tag flush', compact('prefix'));
            } else {
                // Scoped: flush by scope tag (all cache for this user/role)
                $scopeTag = CacheKeyBuilder::buildScopeTag($scope, $identifier);
                Cache::tags([$scopeTag])->flush();
                $this->debug('Scoped tag flush', compact('prefix', 'scope', 'identifier', 'scopeTag'));
            }

            return 1;
        } catch (\Exception $e) {
            Log::error('CacheGroup: Tag invalidation failed', [
                'prefix' => $prefix, 'scope' => $scope, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Invalidate ALL entries for a prefix (regardless of scope).
     * Used for admin operations, data migrations, etc.
     */
    public function invalidateAll(string $prefix, string $scope): int
    {
        try {
            Cache::tags([$prefix])->flush();
            $this->debug('Tag flush all', compact('prefix', 'scope'));
            return 1;
        } catch (\Exception $e) {
            Log::error('CacheGroup: Tag invalidation all failed', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Invalidate resource cache.
     * Same strategy as invalidate() — scope tag only for scoped.
     */
    public function invalidateResource(string $prefix, string $scope = 'global', ?string $identifier = null): int
    {
        try {
            if ($scope === 'global' || $identifier === null) {
                Cache::tags([$prefix])->flush();
            } else {
                $scopeTag = CacheKeyBuilder::buildScopeTag($scope, $identifier);
                Cache::tags([$scopeTag])->flush();
            }
            return 1;
        } catch (\Exception $e) {
            Log::warning('CacheGroup: Tag resource invalidation failed', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function isAvailable(): bool
    {
        try {
            Cache::tags(['__cache_group_test__'])->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function debug(string $message, array $context = []): void
    {
        if (! config('cache-group.debug', false)) {
            return;
        }
        $channel = config('cache-group.debug_channel', 'cache-group');
        try {
            Log::channel($channel)->debug("CacheGroup: {$message}", $context);
        } catch (\Exception $e) {
            Log::debug("CacheGroup: {$message}", $context);
        }
    }
}
