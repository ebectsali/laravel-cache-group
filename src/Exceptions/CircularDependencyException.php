<?php

namespace Ebects\LaravelCacheGroup\Exceptions;

use RuntimeException;

class CircularDependencyException extends RuntimeException
{
    public function __construct(array $chain)
    {
        $path = implode(' → ', $chain);
        parent::__construct(
            "Circular cache dependency detected: {$path}. "
            . "Check your cache group 'also_invalidate' configuration."
        );
    }
}
