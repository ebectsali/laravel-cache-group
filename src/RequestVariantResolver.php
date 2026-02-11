<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\VariantResolver;

/**
 * RequestVariantResolver — Default VariantResolver.
 *
 * Auto-resolves variant from current HTTP request:
 *   route()->getName() + hash(route()->parameters() + request()->all())
 *
 * Output format:
 *   "surat_masuk.main.index:card_a1b2c3d4e5f6"  (with params)
 *   "surat_masuk.main.index"                      (no params)
 *
 * This is the most common pattern for Laravel apps that use
 * route-based caching with request parameter uniqueness.
 */
class RequestVariantResolver implements VariantResolver
{
    /**
     * {@inheritdoc}
     *
     * Auto-resolve from current HTTP context:
     *   - Route name from request()->route()->getName()
     *   - Request uniqueness from route params + query params
     */
    public function resolve(string $prefix): string
    {
        $routeName = request()->route()?->getName() ?? 'unknown';
        $routeParams = request()->route()?->parameters() ?? [];
        $queryParams = request()->all();

        // Merge route params + query params for uniqueness
        $allParams = array_merge($routeParams, $queryParams);

        return $this->buildVariant($routeName, $allParams);
    }

    /**
     * {@inheritdoc}
     *
     * Explicit variant for queue/command context.
     */
    public function resolveExplicit(string $prefix, string $name, array $params = []): string
    {
        return $this->buildVariant($name, $params);
    }

    /**
     * Build variant string from name + params.
     *
     * @param string $name Route name or variant name
     * @param array $params Parameters for uniqueness hash
     * @return string
     */
    protected function buildVariant(string $name, array $params): string
    {
        if (empty($params)) {
            return $name;
        }

        // Sort for consistent hash regardless of parameter order
        ksort($params);
        $paramsHash = substr(md5(serialize($params)), 0, 16);

        return "{$name}:card_{$paramsHash}";
    }
}
