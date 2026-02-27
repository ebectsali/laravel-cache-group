<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Contracts\VariantResolver;
use Ebects\LaravelCacheGroup\Exceptions\MissingScopeContextException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CacheGroupStore — the READ side of cache groups.
 *
 * Replaces: rememberDataNew() and rememberResourceData()
 * Works with: CacheManager (the WRITE/invalidation side)
 *
 * Uses two swappable contracts:
 *   - ScopeResolver:   resolves WHO (user/role/tenant identifier)
 *   - VariantResolver:  resolves WHAT (route/action + params uniqueness)
 *
 * Usage:
 *   // HTTP context — everything auto-resolved:
 *   CacheGroupStore::remember(fn() => $repo->getList(), 'surat_masuk.main');
 *
 *   // Queue context — explicit scope + variant:
 *   CacheGroupStore::rememberFor(fn() => $repo->getList(), 'surat_masuk.main', 'user', $userId);
 *
 *   // Resource/detail cache:
 *   CacheGroupStore::rememberResource('surat_masuk.main', 'detail', $suratId, fn() => ...);
 */
class CacheGroupStore
{
    // =========================================================================
    // REPLACES: rememberDataNew()
    // =========================================================================

    /**
     * Remember data — auto-resolve variant + scope from HTTP context.
     *
     * @param callable $callback Data fetcher
     * @param string $prefix Cache group prefix
     * @param int|null $ttl Override TTL (null = use group config)
     * @return mixed
     */
    public static function remember(
        callable $callback,
        string $prefix,
        ?int $ttl = null,
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);

            if ($config === null) {
                self::debug("remember: No config for '{$prefix}', executing without cache");
                return $callback();
            }

            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            // Resolve WHO via ScopeResolver
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);
            if ($identifier === false) {
                return $callback();
            }

            // Resolve WHAT via VariantResolver
            $variant = app(VariantResolver::class)->resolve($prefix);

            // Build key + tags
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $variant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            self::debug('remember: lookup', [
                'key' => $cacheKey,
                'tags' => $tags,
                'scope' => $scope,
                'identifier' => $identifier,
                'ttl' => $finalTtl,
            ]);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback, $cacheKey) {
                self::debug('remember: MISS', ['key' => $cacheKey]);
                return $callback();
            });

        } catch (MissingScopeContextException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('CacheGroupStore::remember failed, fallback to callback', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Remember data with explicit scope + variant (queue-safe).
     *
     * @param callable $callback Data fetcher
     * @param string $prefix Cache group prefix
     * @param string $scope Scope name ('user', 'role', etc.)
     * @param mixed $target Scope target (User model, ID, etc.)
     * @param string|null $variantName Explicit variant name (e.g. route name)
     * @param array $variantParams Explicit variant params for uniqueness
     * @param int|null $ttl Override TTL
     * @return mixed
     */
    public static function rememberFor(
        callable $callback,
        string $prefix,
        string $scope,
        mixed $target,
        ?string $variantName = null,
        array $variantParams = [],
        ?int $ttl = null,
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            // Resolve WHO explicitly
            $identifier = null;
            if ($scope !== 'global') {
                $resolver = app(ScopeResolver::class);
                $identifier = $resolver->resolveFor($scope, $target);
            }

            // Resolve WHAT explicitly
            $variant = app(VariantResolver::class)->resolveExplicit(
                $prefix,
                $variantName ?? 'unknown',
                $variantParams
            );

            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $variant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback) {
                return $callback();
            });

        } catch (\Exception $e) {
            Log::error('CacheGroupStore::rememberFor failed', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    // =========================================================================
    // REPLACES: rememberResourceData()
    // =========================================================================

    /**
     * Remember resource/detail data — scope-aware.
     *
     * FIX over Nadine's rememberResourceData():
     * - Tags now include scope identifier → flush per-user, not nuclear
     *
     * @param string $prefix Cache group prefix
     * @param string $variableName Resource type (e.g. 'detail', PrefixCache::VL_GROUP_TU)
     * @param mixed $resourceId Resource identifier
     * @param callable $callback Data fetcher
     * @param int|null $ttl Override TTL
     * @return mixed
     */
    public static function rememberResource(
        string $prefix,
        string $variableName,
        mixed $resourceId,
        callable $callback,
        ?int $ttl = null,
    ): mixed {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);

            if ($config === null) {
                self::debug("rememberResource: No config for '{$prefix}', executing without cache");
                return $callback();
            }

            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $finalTtl = $ttl ?? $config['ttl'] ?? config('cache-group.default_ttl', 3600);

            // Resolve WHO
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);
            if ($identifier === false) {
                return $callback();
            }

            $resourceIdStr = self::normalizeResourceId($resourceId);
            $cacheKey = self::buildResourceCacheKey($prefix, $scope, $identifier, $variableName, $resourceIdStr);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            self::debug('rememberResource: lookup', [
                'key' => $cacheKey,
                'tags' => $tags,
                'variable' => $variableName,
                'resource_id' => $resourceIdStr,
            ]);

            return Cache::tags($tags)->remember($cacheKey, $finalTtl, function () use ($callback, $cacheKey) {
                self::debug('rememberResource: MISS', ['key' => $cacheKey]);
                return $callback();
            });

        } catch (\Exception $e) {
            Log::error('CacheGroupStore::rememberResource failed', [
                'prefix' => $prefix,
                'variable' => $variableName,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Remember resource with explicit scope (queue-safe).
     */
    public static function rememberResourceFor(
        string $prefix,
        string $variableName,
        mixed $resourceId,
        string $scope,
        mixed $target,
        callable $callback,
        ?int $ttl = null,
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
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    public static function has(string $prefix): bool
    {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            if ($identifier === false) {
                return false;
            }

            $variant = app(VariantResolver::class)->resolve($prefix);
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $variant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->has($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function forget(string $prefix): bool
    {
        try {
            $config = CacheRegistry::getGroupConfig($prefix);
            $scope = $config['scope'] ?? $config['type'] ?? 'global';
            $identifier = self::resolveCurrentIdentifier($scope, $prefix, $config);

            if ($identifier === false) {
                return false;
            }

            $variant = app(VariantResolver::class)->resolve($prefix);
            $cacheKey = CacheKeyBuilder::buildKey($prefix, $scope, $identifier, $variant);
            $tags = CacheKeyBuilder::buildTags($prefix, $scope, $identifier);

            return Cache::tags($tags)->forget($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * @return string|null|false  string=identifier, null=global, false=skip
     */
    protected static function resolveCurrentIdentifier(
        string $scope,
        string $prefix,
        ?array $config
    ): string|null|false {
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

        $behavior = $config['on_missing_context']
            ?? config('cache-group.on_missing_context', 'throw');

        return match ($behavior) {
            'skip' => false,
            'invalidate_all' => null,
            default => throw new MissingScopeContextException($scope, $prefix),
        };
    }

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

    protected static function debug(string $message, array $context = []): void
    {
        if (! config('cache-group.debug', false)) {
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
