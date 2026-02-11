<?php

namespace Ebects\LaravelCacheGroup\Strategies;

use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HybridStrategy
 *
 * Auto-detects whether to use Tag-based or Cluster SCAN-based invalidation.
 * Caches the detection result per request lifecycle to avoid repeated checks.
 */
class HybridStrategy implements InvalidationStrategy
{
    protected ?bool $isCluster = null;

    protected TagInvalidationStrategy $tagStrategy;
    protected ClusterScanStrategy $clusterStrategy;

    public function __construct()
    {
        $this->tagStrategy = new TagInvalidationStrategy();
        $this->clusterStrategy = new ClusterScanStrategy();
    }

    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidate($prefix, $scope, $identifier);
        } catch (\Exception $e) {
            if ($strategy instanceof TagInvalidationStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidate($prefix, $scope, $identifier);
            }
            throw $e;
        }
    }

    public function invalidateAll(string $prefix, string $scope): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidateAll($prefix, $scope);
        } catch (\Exception $e) {
            if ($strategy instanceof TagInvalidationStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidateAll($prefix, $scope);
            }
            throw $e;
        }
    }

    public function invalidateResource(string $prefix, string $scope = 'global', ?string $identifier = null): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidateResource($prefix, $scope, $identifier);
        } catch (\Exception $e) {
            if ($strategy instanceof TagInvalidationStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidateResource($prefix, $scope, $identifier);
            }
            throw $e;
        }
    }

    public function isAvailable(): bool
    {
        return $this->tagStrategy->isAvailable() || $this->clusterStrategy->isAvailable();
    }

    public function getActiveStrategyName(): string
    {
        return $this->detectCluster() ? 'cluster' : 'tag';
    }

    protected function resolveStrategy(): InvalidationStrategy
    {
        return $this->detectCluster() ? $this->clusterStrategy : $this->tagStrategy;
    }

    protected function detectCluster(): bool
    {
        if ($this->isCluster !== null) {
            return $this->isCluster;
        }

        $configMode = config('cache-group.redis_cluster_mode');
        if ($configMode !== null) {
            return $this->isCluster = (bool) $configMode;
        }

        try {
            $cached = Cache::get('__cache_group_cluster_detected__');
            if ($cached !== null) {
                return $this->isCluster = (bool) $cached;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        $clusters = config('database.redis.clusters');
        if (! empty($clusters)) {
            $this->markAsCluster();
            return $this->isCluster = true;
        }

        try {
            Cache::tags(['__cache_group_detection__'])->put('__test__', true, 1);
            Cache::tags(['__cache_group_detection__'])->flush();
            return $this->isCluster = false;
        } catch (\Exception $e) {
            if ($this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->isCluster = true;
            }
            return $this->isCluster = false;
        }
    }

    protected function isClusterError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'redis-cluster')
            || str_contains($message, 'cannot use')
            || str_contains($message, 'cluster')
            || str_contains($message, 'crossslot');
    }

    protected function markAsCluster(): void
    {
        $this->isCluster = true;

        try {
            Cache::put('__cache_group_cluster_detected__', true, 3600);
        } catch (\Exception $e) {
            // Ignore
        }

        Log::info('CacheGroup: Redis Cluster detected, using SCAN strategy');
    }

    public function resetDetection(): void
    {
        $this->isCluster = null;

        try {
            Cache::forget('__cache_group_cluster_detected__');
        } catch (\Exception $e) {
            // Ignore
        }
    }
}
