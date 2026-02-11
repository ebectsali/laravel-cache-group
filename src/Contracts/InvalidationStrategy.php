<?php

namespace Ebects\LaravelCacheGroup\Contracts;

/**
 * InvalidationStrategy Contract
 *
 * Defines how cache keys are deleted from the store.
 * Two implementations provided: TagStrategy (single Redis) and ClusterScanStrategy (Redis Cluster).
 */
interface InvalidationStrategy
{
    /**
     * Invalidate cache for a specific scope and prefix.
     *
     * @param string $prefix Cache group prefix
     * @param string $scope Scope type ('global', 'user', 'role', 'tenant', etc.)
     * @param string|null $identifier Scope identifier (null for global)
     * @return int Number of keys deleted
     */
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int;

    /**
     * Invalidate ALL cache for a prefix across all scopes.
     *
     * Used for nuclear invalidation (e.g. master data changed).
     *
     * @param string $prefix Cache group prefix
     * @param string $scope Scope type
     * @return int Number of keys deleted
     */
    public function invalidateAll(string $prefix, string $scope): int;

    /**
     * Invalidate resource cache (detail/single record cache).
     *
     * @param string $prefix Cache group prefix
     * @param string|null $identifier Optional scope identifier
     * @return int Number of keys deleted
     */
    public function invalidateResource(string $prefix, ?string $identifier = null): int;

    /**
     * Check if this strategy is available in the current environment.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
