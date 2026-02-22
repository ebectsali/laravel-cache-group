<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Events\CacheInvalidated;
use Ebects\LaravelCacheGroup\Events\InvalidationFailed;
use Ebects\LaravelCacheGroup\Exceptions\MissingScopeContextException;
use Ebects\LaravelCacheGroup\Exceptions\UnresolvableScopeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheManager
{
    public function __construct(
        protected ScopeResolver $scopeResolver,
        protected InvalidationStrategy $strategy,
    ) {}

    public function invalidate(string $prefix): int
    {
        $scope = CacheRegistry::getScopeForPrefix($prefix);

        if ($scope === 'global') {
            return $this->performInvalidation($prefix, $scope, null);
        }

        if (! $this->scopeResolver->hasActiveSession()) {
            return $this->handleMissingContext($prefix, $scope);
        }

        $identifier = $this->scopeResolver->resolve($scope);

        if ($identifier === null) {
            return $this->handleMissingContext($prefix, $scope);
        }

        return $this->performInvalidation($prefix, $scope, $identifier);
    }

    public function invalidateFor(string $prefix, string $scope, mixed $target): int
    {
        if ($scope === 'global') {
            return $this->performInvalidation($prefix, 'global', null);
        }

        $identifier = $this->scopeResolver->resolveFor($scope, $target);

        if ($identifier === null) {
            throw new UnresolvableScopeException($scope, $target);
        }

        return $this->performInvalidation($prefix, $scope, $identifier);
    }

    public function invalidateAll(string $prefix): int
    {
        $scope = CacheRegistry::getScopeForPrefix($prefix);
        $start = microtime(true);

        try {
            $deleted = $this->strategy->invalidateAll($prefix, $scope);
            $deleted += $this->strategy->invalidateResource($prefix, $scope);

            $duration = (microtime(true) - $start) * 1000;
            CacheInvalidated::dispatch($prefix, $scope, '*', $deleted, $this->getStrategyName(), $duration);

            return $deleted;
        } catch (\Exception $e) {
            InvalidationFailed::dispatch($prefix, $scope, '*', $e->getMessage());
            Log::error('CacheGroup: InvalidateAll failed', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function invalidateByClass(string $actionClass): int
    {
        $prefixes = CacheRegistry::getPrefixesForClass($actionClass);
        $totalDeleted = 0;
        $visited = []; // shared across all prefixes to prevent duplicate invalidation

        foreach ($prefixes as $prefix) {
            if (in_array($prefix, $visited, true)) {
                continue;
            }
            $visited[] = $prefix;
            $totalDeleted += $this->invalidate($prefix);
            $totalDeleted += $this->invalidateRelated($prefix, $visited);
        }

        return $totalDeleted;
    }

    public function invalidateByClassFor(string $actionClass, string $scope, mixed $target): int
    {
        $prefixes = CacheRegistry::getPrefixesForClass($actionClass);
        $totalDeleted = 0;
        $visited = []; // shared across all prefixes

        foreach ($prefixes as $prefix) {
            if (in_array($prefix, $visited, true)) {
                continue;
            }
            $visited[] = $prefix;
            $totalDeleted += $this->invalidateFor($prefix, $scope, $target);
            $totalDeleted += $this->invalidateRelatedFor($prefix, $scope, $target, $visited);
        }

        return $totalDeleted;
    }

    public function forceInvalidate(string $prefix, ?string $throttleKey = null): array
    {
        $config = CacheRegistry::getGroupConfig($prefix);
        $throttleConfig = $config['throttle'] ?? config('cache-group.throttle', []);

        if (! ($throttleConfig['enabled'] ?? config('cache-group.throttle.enabled', true))) {
            $this->invalidate($prefix);
            $this->invalidateRelated($prefix);
            return ['invalidated' => true, 'throttled' => false, 'retry_after' => null];
        }

        $window = (int) ($throttleConfig['window'] ?? config('cache-group.throttle.window', 5));
        $strategy = $throttleConfig['strategy'] ?? config('cache-group.throttle.strategy', 'throttle');

        $scope = CacheRegistry::getScopeForPrefix($prefix);
        if ($throttleKey === null && $scope !== 'global') {
            $throttleKey = $this->scopeResolver->resolve($scope) ?? 'global';
        }
        $lockKey = "cache_group_throttle:{$prefix}:{$throttleKey}";

        if ($strategy === 'debounce') {
            return $this->debounceInvalidation($prefix, $lockKey, $window);
        }

        return $this->throttleInvalidation($prefix, $lockKey, $window);
    }

    public function invalidateRelated(string $prefix, array &$visited = []): int
    {
        if (! in_array($prefix, $visited, true)) {
            $visited[] = $prefix;
        }

        $relatedPrefixes = CacheRegistry::getRelatedPrefixes($prefix);
        $totalDeleted = 0;

        foreach ($relatedPrefixes as $relatedPrefix) {
            if (in_array($relatedPrefix, $visited, true)) {
                continue; // already invalidated, skip
            }

            try {
                $visited[] = $relatedPrefix;
                $totalDeleted += $this->invalidate($relatedPrefix);
                $totalDeleted += $this->invalidateRelated($relatedPrefix, $visited);
            } catch (\Exception $e) {
                Log::warning('CacheGroup: Failed to invalidate related', [
                    'source' => $prefix,
                    'related' => $relatedPrefix,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalDeleted;
    }

    public function invalidateRelatedFor(string $prefix, string $scope, mixed $target, array &$visited = []): int
    {
        if (! in_array($prefix, $visited, true)) {
            $visited[] = $prefix;
        }

        $relatedPrefixes = CacheRegistry::getRelatedPrefixes($prefix);
        $totalDeleted = 0;

        foreach ($relatedPrefixes as $relatedPrefix) {
            if (in_array($relatedPrefix, $visited, true)) {
                continue; // already invalidated, skip
            }

            try {
                $visited[] = $relatedPrefix;
                $relatedScope = CacheRegistry::getScopeForPrefix($relatedPrefix);

                if ($relatedScope === 'global') {
                    $totalDeleted += $this->performInvalidation($relatedPrefix, 'global', null);
                } else {
                    $totalDeleted += $this->invalidateFor($relatedPrefix, $scope, $target);
                }

                $totalDeleted += $this->invalidateRelatedFor($relatedPrefix, $scope, $target, $visited);
            } catch (\Exception $e) {
                Log::warning('CacheGroup: Failed to invalidate related for target', [
                    'source' => $prefix,
                    'related' => $relatedPrefix,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalDeleted;
    }

    protected function performInvalidation(string $prefix, string $scope, ?string $identifier): int
    {
        $start = microtime(true);

        try {
            $deleted = $this->strategy->invalidate($prefix, $scope, $identifier);
            $deleted += $this->strategy->invalidateResource($prefix, $scope, $identifier);

            $duration = (microtime(true) - $start) * 1000;
            CacheInvalidated::dispatch($prefix, $scope, $identifier, $deleted, $this->getStrategyName(), $duration);

            return $deleted;
        } catch (\Exception $e) {
            InvalidationFailed::dispatch($prefix, $scope, $identifier, $e->getMessage());
            Log::error('CacheGroup: Invalidation failed', [
                'prefix' => $prefix,
                'scope' => $scope,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    protected function handleMissingContext(string $prefix, string $scope): int
    {
        $groupConfig = CacheRegistry::getGroupConfig($prefix);
        $behavior = $groupConfig['on_missing_context']
            ?? config('cache-group.on_missing_context', 'throw');

        return match ($behavior) {
            'skip' => 0,
            'invalidate_all' => $this->invalidateAll($prefix),
            default => throw new MissingScopeContextException($scope, $prefix),
        };
    }

    protected function throttleInvalidation(string $prefix, string $lockKey, int $window): array
    {
        $lock = Cache::get($lockKey);

        if ($lock !== null) {
            return [
                'invalidated' => false,
                'throttled' => true,
                'retry_after' => $window,
            ];
        }

        Cache::put($lockKey, microtime(true), $window);
        $this->invalidate($prefix);
        $this->invalidateRelated($prefix);

        return ['invalidated' => true, 'throttled' => false, 'retry_after' => null];
    }

    protected function debounceInvalidation(string $prefix, string $lockKey, int $window): array
    {
        Cache::put($lockKey, microtime(true), $window);

        $pendingKey = "{$lockKey}:pending";
        $isPending = Cache::get($pendingKey);

        if (! $isPending) {
            Cache::put($pendingKey, true, $window + 1);
            $this->invalidate($prefix);
            $this->invalidateRelated($prefix);

            return ['invalidated' => true, 'throttled' => false, 'retry_after' => null];
        }

        return ['invalidated' => false, 'throttled' => true, 'retry_after' => $window];
    }

    protected function getStrategyName(): string
    {
        if ($this->strategy instanceof Strategies\HybridStrategy) {
            return $this->strategy->getActiveStrategyName();
        }

        return match (true) {
            $this->strategy instanceof Strategies\TagInvalidationStrategy => 'tag',
            $this->strategy instanceof Strategies\ClusterScanStrategy => 'cluster',
            default => 'unknown',
        };
    }
}
