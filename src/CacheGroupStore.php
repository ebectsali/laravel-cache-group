<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Exceptions\MissingScopeContextException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CacheGroupStore — the READ side of cache groups.
 *
 * Replaces: rememberDataNew() and rememberResourceData()
 * Works with: CacheManager (the WRITE/invalidation side)
 *
 * Key improvements over rememberDataNew/rememberResourceData:
 * 1. Resource cache is NOW scope-aware (tag includes user/role identifier)
 * 2. Consistent key format via CacheKeyBuilder (no space bugs)
 * 3. Works in both HTTP context (auto-resolve) and queue context (explicit target)
 * 4. Graceful fallback — cache failure never breaks the app
 */
class CacheGroupStore
{
    // =========================================================================
    // REPLACES: rememberDataNew()
    // =========================================================================

    /**
     * Remember data with automatic scope resolution from current auth context.
     *
     * BEFORE (Nadine):
     *   return rememberDataNew(fn() => $this->fetchData());
     *
     * AFTER (Library):
     *   return CacheGroupStore::remember('surat_masuk.main', 'list', fn() => $this->fetchData());
     *   // or with extra params for unique cache per pagination/filter:
     *   return CacheGroupStore::remember('surat_masuk.main', 'list', fn() => $this->fetchData(), extraParams: request()->all());
     *
     * @param string $prefix Cache group prefix (must be registered in CacheRegistry)
     * @param string $variant Cache variant (e.g. 'list', 'summary', 'page_1')
     * @param callable $callback Data fetcher
     * @param int|null $ttl Override TTL in seconds (null = use group config)
     * @param array $extraParams Extra params to make cache key unique (filters, pagination, etc.)
     * @return mixed
     */
    public static function remember(
        string $prefix,
        string $variant,
        callable $callback,
        ?int $ttl = null,
        array $extraParams = []
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);

            if ($config === null) {
                self::debug("remember: No config for '{$prefix}', executing without cache");
                return $callback();
            }

            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            // Resolve scope identifier from current auth
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            // If resolution failed and behavior = skip → execute without cache
            if ($identifier === false) {
                return $callback();
            }

            // Build unique variant with extra params
            $fullVariant = self::buildVariant($variant, $extraParams);

            // Build key and tags via CacheKeyBuilder (no space bugs!)
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $fullVariant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            self::debug('remember: lookup', [
                'key' => $cacheKey, 'tags' => $tags,
                'scope' => $scope, 'identifier' => $identifier,
            ]);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback, $cacheKey) {
                self::debug('remember: MISS', ['key' => $cacheKey]);
                return $callback();
            });

        } catch (MissingScopeContextException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('CacheGroupStore::remember failed, fallback to callback', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Remember data with explicit scope + target (queue-safe).
     *
     * BEFORE (Nadine): Not possible — rememberDataNew() always needs HTTP context.
     *
     * AFTER (Library):
     *   return CacheGroupStore::rememberFor('surat_masuk.main', 'list', 'user', $userId, fn() => $this->fetchData());
     */
    public static function rememberFor(
        string $prefix,
        string $variant,
        string $scope,
        mixed $target,
        callable $callback,
        ?int $ttl = null,
        array $extraParams = []
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            $identifier = null;
            if ($scope !== 'global') {
                $resolver = app(ScopeResolver::class);
                $identifier = $resolver->resolveFor($scope, $target);
            }

            $fullVariant = self::buildVariant($variant, $extraParams);
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $fullVariant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback) {
                return $callback();
            });

        } catch (\Exception $e) {
            Log::error('CacheGroupStore::rememberFor failed', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    // =========================================================================
    // REPLACES: rememberResourceData()
    // =========================================================================

    /**
     * Remember resource/detail data — NOW scope-aware.
     *
     * KEY FIX: Tags now include scope identifier, so invalidating user A's
     * resource cache does NOT affect user B.
     *
     * BEFORE (Nadine):
     *   $tags = [$prefix];  // ← ALL users share same tag = nuclear flush
     *   return rememberResourceData(fn() => ..., 'surat_masuk.main', 'detail', $suratId);
     *
     * AFTER (Library):
     *   return CacheGroupStore::rememberResource('surat_masuk.main', 'detail', $suratId, fn() => ...);
     *   // Tags = ['surat_masuk.main', 'user_ABC123'] ← only this user's cache gets flushed
     *
     * @param string $prefix Cache group prefix
     * @param string $variableName Resource type (e.g. 'detail', 'verifikator_list', PrefixCache::VL_GROUP_TU)
     * @param mixed $resourceId Resource identifier (surat ID, hash, etc.)
     * @param callable $callback Data fetcher
     * @param int|null $ttl Override TTL
     * @return mixed
     */
    public static function rememberResource(
        string $prefix,
        string $variableName,
        mixed $resourceId,
        callable $callback,
        ?int $ttl = null
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);

            if ($config === null) {
                self::debug("rememberResource: No config for '{$prefix}', executing without cache");
                return $callback();
            }

            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            // 🔥 THE FIX: Resolve scope for resource cache too!
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            if ($identifier === false) {
                return $callback();
            }

            $resourceIdStr = self::normalizeResourceId($resourceId);

            // Build resource key WITH scope (unlike Nadine's format)
            $cacheKey = self::buildResourceCacheKey($prefix, $scope, $identifier, $variableName, $resourceIdStr);

            // 🔥 THE FIX: Composite tags [prefix, scope_identifier]
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            self::debug('rememberResource: lookup', [
                'key' => $cacheKey, 'tags' => $tags,
                'variable' => $variableName, 'resource_id' => $resourceIdStr,
            ]);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback, $cacheKey) {
                self::debug('rememberResource: MISS', ['key' => $cacheKey]);
                return $callback();
            });

        } catch (\Exception $e) {
            Log::error('CacheGroupStore::rememberResource failed', [
                'prefix' => $prefix, 'variable' => $variableName,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Remember resource with explicit scope (queue-safe).
     *
     * BEFORE (Nadine): Not possible — rememberResourceData() has no scope parameter.
     *
     * AFTER:
     *   return CacheGroupStore::rememberResourceFor(
     *       'surat_masuk.main', 'detail', $suratId,
     *       'user', $userId, fn() => ...
     *   );
     */
    public static function rememberResourceFor(
        string $prefix,
        string $variableName,
        mixed $resourceId,
        string $scope,
        mixed $target,
        callable $callback,
        ?int $ttl = null
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            $identifier = null;
            if ($scope !== 'global') {
                $resolver = app(ScopeResolver::class);
                $identifier = $resolver->resolveFor($scope, $target);
            }

            $resourceIdStr = self::normalizeResourceId($resourceId);
            $cacheKey = self::buildResourceCacheKey($prefix, $scope, $identifier, $variableName, $resourceIdStr);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback) {
                return $callback();
            });

        } catch (\Exception $e) {
            Log::error('CacheGroupStore::rememberResourceFor failed', [
                'prefix' => $prefix, 'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Check if cache exists.
     */
    public static function has(string $prefix, string $variant, array $extraParams = []): bool
    {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $scope = $config['scope'] ?? 'global';
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            if ($identifier === false) {
                return false;
            }

            $fullVariant = self::buildVariant($variant, $extraParams);
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $fullVariant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->has($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Forget specific cache entry.
     */
    public static function forget(string $prefix, string $variant, array $extraParams = []): bool
    {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $scope = $config['scope'] ?? 'global';
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            if ($identifier === false) {
                return false;
            }

            $fullVariant = self::buildVariant($variant, $extraParams);
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $fullVariant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->forget($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Resolve scope identifier from current auth context.
     *
     * @return string|null|false  string=identifier, null=global, false=skip(no cache)
     */
    protected static function resolveCurrentIdentifier(string $scope, string $prefix, ?array $config): string|null|false
    {
        if ($scope === 'global') {
            return null;
        }

        $resolver = app(ScopeResolver::class);

        if ($resolver->hasActiveSession()) {
            $identifier = $resolver->resolve($scope);
            if ($identifier !== null) {
                return $identifier;
            }
        }

        // No active session or resolver returned null
        $behavior = $config['on_missing_context']
            ?? config('cache-group.on_missing_context', 'throw');

        return match ($behavior) {
            'skip' => false,
            'invalidate_all' => null, // treat as global
            default => throw new MissingScopeContextException($scope, $prefix),
        };
    }

    /**
     * Build variant string with extra params hash for uniqueness.
     *
     * Replaces Nadine's: hash(serialize(request()->all()))
     */
    protected static function buildVariant(string $variant, array $extraParams): string
    {
        if (empty($extraParams)) {
            return $variant;
        }

        // Sort for consistent hash regardless of parameter order
        ksort($extraParams);
        $paramsHash = substr(md5(serialize($extraParams)), 0, 12);

        return "{$variant}:{$paramsHash}";
    }

    /**
     * Build resource cache key WITH scope.
     *
     * Nadine format (no scope):
     *   nadine_cache:resource:surat_masuk.main:var_detail:id_123
     *
     * Library format (with scope):
     *   test_cache:resource:user:user_ABC123:surat_masuk.main:var_detail:id_123
     */
    protected static function buildResourceCacheKey(
        string $prefix,
        string $scope,
        ?string $identifier,
        string $variableName,
        string $resourceId
    ): string {
        $cachePrefix = CacheKeyBuilder::getCachePrefix();

        $parts = [$cachePrefix, 'resource'];

        if ($scope !== 'global' && $identifier !== null) {
            $parts[] = $scope;
            $parts[] = "{$scope}_{$identifier}";
        } else {
            $parts[] = 'global';
        }

        $parts[] = $prefix;
        $parts[] = "var_{$variableName}";

        if ($resourceId !== '') {
            $parts[] = "id_{$resourceId}";
        }

        return implode(':', $parts);
    }

    /**
     * Normalize resource ID to string.
     */
    protected static function normalizeResourceId(mixed $resourceId): string
    {
        if ($resourceId === null) {
            return '';
        }

        if (is_array($resourceId)) {
            return implode('_', $resourceId);
        }

        return (string) $resourceId;
    }

    /**
     * Debug logging.
     */
    protected static function debug(string $message, array $context = []): void
    {
        if (!config('cache-group.debug', false)) {
            return;
        }

        $channel = config('cache-group.debug_channel', 'cache-group');

        try {
            Log::channel($channel)->debug("CacheGroupStore: {$message}", $context);
        } catch (\Exception $e) {
            Log::debug("CacheGroupStore: {$message}", $context);
        }
    }
}
