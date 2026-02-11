<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;

/**
 * DefaultScopeResolver
 *
 * Fallback scope resolver that uses Laravel's built-in auth system.
 * Works with: Sanctum, Passport, session-based auth.
 *
 * For custom auth (JWT, etc.), implement your own ScopeResolver.
 */
class DefaultScopeResolver implements ScopeResolver
{
    /**
     * {@inheritdoc}
     */
    public function resolve(string $scope): ?string
    {
        if ($scope === 'global') {
            return null;
        }

        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return match ($scope) {
            'user' => (string) $user->getAuthIdentifier(),
            'role' => $this->resolveRole($user),
            default => $this->resolveCustomScope($user, $scope),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function resolveFor(string $scope, mixed $target): ?string
    {
        if ($scope === 'global') {
            return null;
        }

        // If target is already a string, use it directly
        if (is_string($target)) {
            return $target;
        }

        // If target is numeric (ID), cast to string
        if (is_numeric($target)) {
            return (string) $target;
        }

        // If target is an object (model), try to resolve
        if (is_object($target)) {
            return match ($scope) {
                'user' => (string) ($target->getAuthIdentifier ?? $target->id ?? null),
                'role' => $this->resolveRole($target),
                default => $this->resolveCustomScope($target, $scope),
            };
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasActiveSession(): bool
    {
        try {
            return auth()->check();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Try to resolve role from user model.
     *
     * Checks common role property/method names.
     *
     * @param object $user
     * @return string|null
     */
    protected function resolveRole(object $user): ?string
    {
        // Common role property names
        $properties = ['role', 'role_name', 'kode_role', 'role_id'];

        foreach ($properties as $prop) {
            if (isset($user->{$prop})) {
                return (string) $user->{$prop};
            }
        }

        // Common role method names
        $methods = ['getRole', 'getRoleName', 'role'];

        foreach ($methods as $method) {
            if (method_exists($user, $method)) {
                $result = $user->{$method}();

                if (is_string($result)) {
                    return $result;
                }
                if (is_object($result) && isset($result->name)) {
                    return (string) $result->name;
                }
            }
        }

        return null;
    }

    /**
     * Try to resolve a custom scope from user model.
     *
     * Looks for a property or method matching the scope name.
     *
     * @param object $user
     * @param string $scope
     * @return string|null
     */
    protected function resolveCustomScope(object $user, string $scope): ?string
    {
        // Try as property: $user->tenant_id for scope 'tenant'
        $propertyName = $scope . '_id';
        if (isset($user->{$propertyName})) {
            return (string) $user->{$propertyName};
        }

        // Try direct property: $user->tenant
        if (isset($user->{$scope}) && (is_string($user->{$scope}) || is_numeric($user->{$scope}))) {
            return (string) $user->{$scope};
        }

        // Try as method: $user->getTenant()
        $methodName = 'get' . ucfirst($scope);
        if (method_exists($user, $methodName)) {
            $result = $user->{$methodName}();
            if (is_string($result) || is_numeric($result)) {
                return (string) $result;
            }
        }

        return null;
    }
}
