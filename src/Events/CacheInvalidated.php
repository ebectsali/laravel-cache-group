<?php

namespace Ebects\LaravelCacheGroup\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CacheInvalidated
{
    use Dispatchable;

    public function __construct(
        public readonly string $prefix,
        public readonly string $scope,
        public readonly ?string $identifier,
        public readonly int $deletedCount,
        public readonly string $strategy, // 'tag' or 'cluster'
        public readonly float $duration, // milliseconds
    ) {}
}
