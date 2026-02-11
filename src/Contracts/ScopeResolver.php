<?php

namespace Ebects\LaravelCacheGroup\Contracts;

/**
 * ScopeResolver Contract
 *
 * Implement this interface to tell the library how to resolve
 * scope identifiers for your application.
 *
 * Works with any auth system: Laravel Sanctum, JWT, Passport, or custom.
 *
 * @example
 * class MyScopeResolver implements ScopeResolver
 * {
 *     public function resolve(string $scope): ?string
 *     {
 *         return match($scope) {
 *             'user' => auth()->id(),
 *             'role' => auth()->user()?->role,
 *             'tenant' => auth()->user()?->tenant_id,
 *             default => null,
 *         };
 *     }
 * }
 */
interface ScopeResolver
{
    /**
     * Resolve identifier for a scope from the current session/auth context.
     *
     * Used when: HTTP request, controller, middleware — where auth is available.
     *
     * @param string $scope The scope name (e.g. 'user', 'role', 'tenant')
     * @return string|null The resolved identifier, or null if:
     *                     - Scope is 'global' (no identifier needed)
     *                     - Auth context is not available (e.g. queue job)
     */
    public function resolve(string $scope): ?string;

    /**
     * Resolve identifier from an explicit target.
     *
     * Used when: queue job, event listener, artisan command — where you pass
     * the target explicitly because there's no auth session.
     *
     * @param string $scope The scope name (e.g. 'user', 'role', 'tenant')
     * @param mixed $target The target to resolve from (User model, string ID, etc.)
     * @return string|null The resolved identifier
     */
    public function resolveFor(string $scope, mixed $target): ?string;

    /**
     * Check if the current context has an active auth session.
     *
     * The library uses this to decide whether resolve() can be called,
     * or if it should throw MissingScopeContextException.
     *
     * @return bool
     */
    public function hasActiveSession(): bool;
}
