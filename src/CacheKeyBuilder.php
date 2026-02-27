<?php

namespace Ebects\LaravelCacheGroup;

/**
 * CacheKeyBuilder
 *
 * Centralized cache key and pattern generation.
 * Prevents inconsistent key formats and the space-in-pattern bugs
 * that cause silent invalidation failures.
 *
 * Key format: {prefix}:cache:{scope}:{scope}_{identifier}:{group}:{variant}
 * Pattern format: {prefix}:cache:{scope}:{scope}_{identifier}:{group}:*
 */
class CacheKeyBuilder
{
    /**
     * Build a cache key for storing/retrieving data.
     *
     * @param string $group Cache group prefix (e.g. 'statistik')
     * @param string $scope Scope type ('global', 'user', 'role', etc.)
     * @param string|null $identifier Scope identifier (null for global)
     * @param string $variant Cache variant (e.g. 'page_1', 'summary')
     * @return string
     */
    public static function buildKey(
        string $group,
        string $scope,
        ?string $identifier,
        string $variant
    ): string {
        $cachePrefix = self::getCachePrefix();
        $parts = self::buildParts($cachePrefix, $group, $scope, $identifier);
        $parts[] = $variant;

        return implode(':', array_filter($parts, fn($p) => $p !== null && $p !== ''));
    }

    /**
     * Build a SCAN pattern for invalidation.
     *
     * @param string $group Cache group prefix
     * @param string $scope Scope type
     * @param string|null $identifier Scope identifier (null = current scope, '*' = all)
     * @return string Pattern with wildcard
     */
    public static function buildPattern(
        string $group,
        string $scope,
        ?string $identifier = null
    ): string {
        $cachePrefix = self::getCachePrefix();
        $parts = self::buildParts($cachePrefix, $group, $scope, $identifier);
        $parts[] = '*';

        return implode(':', array_filter($parts, fn($p) => $p !== null && $p !== ''));
    }

    /**
     * Build a hash-aware pattern for Redis Cluster SCAN.
     * Cluster nodes use hash slots, so keys may have a hash prefix.
     *
     * @param string $group Cache group prefix
     * @param string $scope Scope type
     * @param string|null $identifier Scope identifier
     * @return string Hash-aware pattern
     */
    public static function buildClusterPattern(
        string $group,
        string $scope,
        ?string $identifier = null
    ): string {
        $basePattern = self::buildPattern($group, $scope, $identifier);

        return '*:' . $basePattern;
    }

    /**
     * Build a resource cache pattern (scope-aware).
     *
     * NEW format: {prefix}:resource:{scope}:{scope_id}:{group}:*
     * OLD format: {prefix}:resource:{group}:*  (no scope = nuclear flush)
     *
     * @param string $group Cache group prefix
     * @param string $scope Scope type ('global', 'user', 'role', etc.)
     * @param string|null $identifier Scope identifier (null = wildcard all)
     * @return string Resource pattern
     */
    public static function buildResourcePattern(
        string $group,
        string $scope = 'global',
        ?string $identifier = null
    ): string {
        $cachePrefix = self::getCachePrefix();

        if ($scope !== 'global' && $identifier !== null) {
            return "{$cachePrefix}:resource:{$scope}:{$scope}_{$identifier}:{$group}:*";
        }

        if ($scope !== 'global' && $identifier === null) {
            // Wildcard: match ALL users/roles for this group
            return "{$cachePrefix}:resource:{$scope}:{$scope}_*:{$group}:*";
        }

        // Global resource
        return "{$cachePrefix}:resource:global:{$group}:*";
    }

    /**
     * Build a resource cache pattern for cluster.
     *
     * @param string $group Cache group prefix
     * @param string $scope Scope type
     * @param string|null $identifier Scope identifier
     * @return string Hash-aware resource pattern
     */
    public static function buildClusterResourcePattern(
        string $group,
        string $scope = 'global',
        ?string $identifier = null
    ): string {
        return '*:' . self::buildResourcePattern($group, $scope, $identifier);
    }

    /**
     * Build tags array for Cache::tags() usage.
     * Uses composite tags for precise invalidation.
     *
     * @param string $group Cache group prefix
     * @param string $scope Scope type
     * @param string|null $identifier Scope identifier
     * @return array Tags array
     */
    public static function buildTags(
        string $group,
        string $scope,
        ?string $identifier = null
    ): array {
        $tags = [$group];

        if ($scope !== 'global' && $identifier !== null) {
            $tags[] = "{$scope}_{$identifier}";
        }

        return $tags;
    }

    /**
     * Build scope tag only (for broad scope invalidation).
     *
     * @param string $scope Scope type
     * @param string $identifier Scope identifier
     * @return string
     */
    public static function buildScopeTag(string $scope, string $identifier): string
    {
        return "{$scope}_{$identifier}";
    }

    /**
     * Build the common parts of a cache key/pattern.
     *
     * @param string $cachePrefix
     * @param string $group
     * @param string $scope
     * @param string|null $identifier
     * @return array
     */
    protected static function buildParts(
        string $cachePrefix,
        string $group,
        string $scope,
        ?string $identifier
    ): array {
        $parts = [$cachePrefix, 'cache'];

        if ($scope === 'global') {
            $parts[] = 'global';
        } else {
            $parts[] = $scope;
            if ($identifier !== null) {
                $parts[] = "{$scope}_{$identifier}";
            } else {
                $parts[] = "{$scope}_*";
            }
        }

        $parts[] = $group;

        return $parts;
    }

    /**
     * Get the configured cache prefix.
     *
     * @return string
     */
    public static function getCachePrefix(): string
    {
        return config('cache-group.cache_prefix')
            ?? config('cache.prefix', 'laravel_cache');
    }
}
