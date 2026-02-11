<?php

namespace Ebects\LaravelCacheGroup\Contracts;

/**
 * VariantResolver Contract
 *
 * Determines the "variant" portion of a cache key.
 * The variant makes each cache entry unique within the same prefix + scope.
 *
 * Default implementation (RequestVariantResolver):
 *   route_name:card_{hash(request_params)}
 *
 * Custom implementations can use anything:
 *   - Route-based (default)
 *   - Action-class based
 *   - Custom string
 *   - Combination
 *
 * Cache key anatomy:
 *   {cache_prefix}:cache:{scope}:{scope_id}:{group}:{variant}
 *                                                    ^^^^^^^^
 *                                              this is what VariantResolver produces
 */
interface VariantResolver
{
    /**
     * Resolve variant from current HTTP context (auto).
     *
     * Called by CacheGroupStore::remember() in HTTP context.
     * Has access to request(), route(), etc.
     *
     * @param string $prefix Cache group prefix (for context)
     * @return string Variant string for cache key
     */
    public function resolve(string $prefix): string;

    /**
     * Resolve variant from explicit parameters (manual).
     *
     * Called by CacheGroupStore::rememberFor() in queue/command context.
     * No HTTP context available.
     *
     * @param string $prefix Cache group prefix
     * @param string $name Variant name (e.g. route name, action name)
     * @param array $params Parameters for uniqueness
     * @return string Variant string for cache key
     */
    public function resolveExplicit(string $prefix, string $name, array $params = []): string;
}
