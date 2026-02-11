<?php

namespace Ebects\LaravelCacheGroup\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int invalidate(string $prefix)
 * @method static int invalidateFor(string $prefix, string $scope, mixed $target)
 * @method static int invalidateAll(string $prefix)
 * @method static int invalidateByClass(string $actionClass)
 * @method static int invalidateByClassFor(string $actionClass, string $scope, mixed $target)
 * @method static array forceInvalidate(string $prefix, ?string $throttleKey = null)
 * @method static int invalidateRelated(string $prefix, array $visited = [])
 *
 * @see \Ebects\LaravelCacheGroup\CacheManager
 */
class CacheGroup extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ebects\LaravelCacheGroup\CacheManager::class;
    }
}
