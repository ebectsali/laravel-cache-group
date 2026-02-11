<?php

namespace Ebects\LaravelCacheGroup\Strategies;

use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * HybridStrategy
 *
 * Auto-detects whether to use Tag-based or Cluster SCAN-based invalidation.
 * Caches the detection result per request lifecycle to avoid repeated checks.
 *
 * Detection order:
 * 1. Explicit config (cache-group.redis_cluster_mode)
 * 2. Previous detection result (cached in Redis)
 * 3. Runtime detection (test Cache::tags())
 */
class HybridStrategy implements InvalidationStrategy
{
    protected ?bool $isCluster = null;

    protected TagStrategy $tagStrategy;
    protected ClusterScanStrategy $clusterStrategy;

    public function __construct()
    {
        $this->tagStrategy = new TagStrategy();
        $this->clusterStrategy = new ClusterScanStrategy();
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidate($prefix, $scope, $identifier);
        } catch (\Exception $e) {
            // If tag strategy fails with cluster error, switch to cluster
            if ($strategy instanceof TagStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidate($prefix, $scope, $identifier);
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateAll(string $prefix, string $scope): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidateAll($prefix, $scope);
        } catch (\Exception $e) {
            if ($strategy instanceof TagStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidateAll($prefix, $scope);
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateResource(string $prefix, ?string $identifier = null): int
    {
        $strategy = $this->resolveStrategy();

        try {
            return $strategy->invalidateResource($prefix, $identifier);
        } catch (\Exception $e) {
            if ($strategy instanceof TagStrategy && $this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->clusterStrategy->invalidateResource($prefix, $identifier);
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->tagStrategy->isAvailable() || $this->clusterStrategy->isAvailable();
    }

    /**
     * Get the currently active strategy name (for debugging/monitoring).
     *
     * @return string 'tag' or 'cluster'
     */
    public function getActiveStrategyName(): string
    {
        return $this->detectCluster() ? 'cluster' : 'tag';
    }

    /**
     * Resolve which strategy to use.
     *
     * @return InvalidationStrategy
     */
    protected function resolveStrategy(): InvalidationStrategy
    {
        return $this->detectCluster() ? $this->clusterStrategy : $this->tagStrategy;
    }

    /**
     * Detect if Redis Cluster is being used.
     * Result is memoized per request lifecycle.
     *
     * @return bool
     */
    protected function detectCluster(): bool
    {
        // Memoized for this request
        if ($this->isCluster !== null) {
            return $this->isCluster;
        }

        // 1. Check explicit config
        $configMode = config('cache-group.redis_cluster_mode');
        if ($configMode !== null) {
            return $this->isCluster = (bool) $configMode;
        }

        // 2. Check previous detection result
        try {
            $cached = Cache::get('__cache_group_cluster_detected__');
            if ($cached !== null) {
                return $this->isCluster = (bool) $cached;
            }
        } catch (\Exception $e) {
            // Ignore cache read failures
        }

        // 3. Check Laravel Redis cluster config
        $clusters = config('database.redis.clusters');
        if (! empty($clusters)) {
            $this->markAsCluster();
            return $this->isCluster = true;
        }

        // 4. Runtime detection — try tag operation
        try {
            Cache::tags(['__cache_group_detection__'])->put('__test__', true, 1);
            Cache::tags(['__cache_group_detection__'])->flush();

            return $this->isCluster = false;

        } catch (\Exception $e) {
            if ($this->isClusterError($e)) {
                $this->markAsCluster();
                return $this->isCluster = true;
            }

            // Unknown error — default to tag strategy
            return $this->isCluster = false;
        }
    }

    /**
     * Check if exception is a Redis Cluster error.
     *
     * @param \Exception $e
     * @return bool
     */
    protected function isClusterError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'redis-cluster')
            || str_contains($message, 'cannot use')
            || str_contains($message, 'cluster')
            || str_contains($message, 'crossslot');
    }

    /**
     * Mark that cluster mode was detected (cache for 1 hour).
     */
    protected function markAsCluster(): void
    {
        $this->isCluster = true;

        try {
            Cache::put('__cache_group_cluster_detected__', true, 3600);
        } catch (\Exception $e) {
            // Ignore — detection will repeat next time
        }

        Log::info('CacheGroup: Redis Cluster detected, using SCAN strategy');
    }

    /**
     * Force reset detection cache (useful for testing).
     */
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
