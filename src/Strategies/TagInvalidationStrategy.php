<?php

namespace Ebects\LaravelCacheGroup\Strategies;

use Ebects\LaravelCacheGroup\CacheKeyBuilder;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TagInvalidationStrategy implements InvalidationStrategy
{
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        try {
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);
            Cache::tags($tags)->flush();

            $this->debug('Tag invalidation completed', compact('prefix', 'scope', 'identifier', 'tags'));
            return 1;
        } catch (\Exception $e) {
            Log::error('CacheGroup: Tag invalidation failed', [
                'prefix' => $prefix, 'scope' => $scope, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function invalidateAll(string $prefix, string $scope): int
    {
        try {
            Cache::tags([$prefix])->flush();
            $this->debug('Tag invalidation for all completed', compact('prefix', 'scope'));
            return 1;
        } catch (\Exception $e) {
            Log::error('CacheGroup: Tag invalidation all failed', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function invalidateResource(string $prefix, string $scope = 'global', ?string $identifier = null): int
    {
        try {
            // 🔥 FIX: Use composite tags for precise resource invalidation
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);
            Cache::tags($tags)->flush();
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
