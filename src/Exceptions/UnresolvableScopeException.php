<?php

namespace Ebects\LaravelCacheGroup\Exceptions;

use RuntimeException;

/**
 * Thrown when resolveFor() cannot produce an identifier from the given target.
 */
class UnresolvableScopeException extends RuntimeException
{
    public function __construct(string $scope, mixed $target)
    {
        $targetType = is_object($target) ? get_class($target) : gettype($target);

        parent::__construct(
            "Cannot resolve identifier for scope '{$scope}' from target of type '{$targetType}'. "
            . "Ensure your ScopeResolver::resolveFor() handles this scope and target type."
        );
    }
}
