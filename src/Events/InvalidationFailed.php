<?php

namespace Ebects\LaravelCacheGroup\Events;

use Illuminate\Foundation\Events\Dispatchable;

class InvalidationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $prefix,
        public readonly string $scope,
        public readonly ?string $identifier,
        public readonly string $error,
    ) {}
}
