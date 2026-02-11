<?php

namespace Ebects\LaravelCacheGroup\Exceptions;

use RuntimeException;

/**
 * Thrown when invalidateCache() is called for a scoped cache group
 * without an active auth session (e.g. inside a queue job).
 *
 * Solution: Use invalidateCacheFor($scope, $target) instead.
 */
class MissingScopeContextException extends RuntimeException
{
    public function __construct(string $scope, string $prefix)
    {
        parent::__construct(
            "Cannot resolve scope '{$scope}' for cache group '{$prefix}' — no active session. "
            . "Use invalidateCacheFor('{$scope}', \$target) instead when calling from queue jobs, "
            . "event listeners, or artisan commands."
        );
    }
}
