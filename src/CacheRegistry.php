<?php

namespace Ebects\LaravelCacheGroup;

use Ebects\LaravelCacheGroup\Contracts\CacheGroupInterface;
use Illuminate\Support\Facades\Log;

class CacheRegistry
{
    /** @var array<class-string<CacheGroupInterface>> */
    protected static array $groups = [];

    /**
     * Flat prefix configs — for apps that don't use CacheGroupInterface classes.
     * Format: ['prefix' => ['type' => 'peruser', 'ttl' => 3600, ...], ...]
     */
    protected static array $flatConfigs = [];

    /**
     * Flat class mapping — maps Action classes to prefixes they should invalidate.
     * Format: ['App\Actions\InsertPost::class' => ['posts.list', 'dashboard'], ...]
     */
    protected static array $flatClassMapping = [];

    protected static ?array $routesCache = null;
    protected static ?array $classMappingCache = null;
    protected static ?array $groupsCache = null;

    public static function register(string $groupClass): bool
    {
        if (! is_subclass_of($groupClass, CacheGroupInterface::class)) {
            Log::error("CacheGroup: Cannot register {$groupClass} — must implement CacheGroupInterface");
            return false;
        }

        if (in_array($groupClass, self::$groups, true)) {
            return false;
        }

        self::$groups[] = $groupClass;
        self::clearCache();

        return true;
    }

    public static function registerMany(array $groupClasses): void
    {
        foreach ($groupClasses as $groupClass) {
            self::register($groupClass);
        }
    }

    /**
     * Register flat prefix configs (no CacheGroupInterface class needed).
     *
     * Useful for apps that have their own CacheGroup format and want to
     * feed config directly without implementing CacheGroupInterface.
     *
     * Usage in ServiceProvider:
     *   CacheRegistry::registerFlatConfigs(config('cache-mapping.routes'));
     *
     * @param array<string, array> $configs ['prefix' => ['type' => 'peruser', 'ttl' => 3600, ...]]
     */
    public static function registerFlatConfigs(array $configs): void
    {
        foreach ($configs as $prefix => $config) {
            if (is_string($prefix) && is_array($config)) {
                self::$flatConfigs[$prefix] = $config;
            }
        }
        self::clearCache();
    }

    /**
     * Register flat class mapping (no CacheGroupInterface class needed).
     *
     * Maps Action classes to the prefixes they should invalidate.
     *
     * Usage in ServiceProvider:
     *   CacheRegistry::registerFlatClassMapping(config('cache-mapping.class_mapping'));
     *
     * @param array<string, string[]> $mapping ['ActionClass' => ['prefix1', 'prefix2']]
     */
    public static function registerFlatClassMapping(array $mapping): void
    {
        foreach ($mapping as $class => $prefixes) {
            if (is_string($class) && is_array($prefixes)) {
                if (!isset(self::$flatClassMapping[$class])) {
                    self::$flatClassMapping[$class] = [];
                }
                foreach ($prefixes as $prefix) {
                    if (is_string($prefix) && !in_array($prefix, self::$flatClassMapping[$class], true)) {
                        self::$flatClassMapping[$class][] = $prefix;
                    }
                }
            }
        }
        self::clearCache();
    }

    public static function getRegisteredGroups(): array
    {
        return self::$groups;
    }

    public static function generateRoutesConfig(): array
    {
        if (self::$routesCache !== null) {
            return self::$routesCache;
        }

        // Start with flat configs
        $routes = self::$flatConfigs;

        // Class-based groups override flat configs
        foreach (self::$groups as $groupClass) {
            $prefix = $groupClass::getPrefix();
            $config = $groupClass::getConfig();
            $routes[$prefix] = $config;
        }

        return self::$routesCache = $routes;
    }

    public static function generateClassMapping(): array
    {
        if (self::$classMappingCache !== null) {
            return self::$classMappingCache;
        }

        // Start with flat class mapping
        $mapping = [];
        foreach (self::$flatClassMapping as $class => $prefixes) {
            $mapping[$class] = $prefixes;
        }

        // Class-based groups merge/override
        foreach (self::$groups as $groupClass) {
            $prefix = $groupClass::getPrefix();
            $removeClasses = $groupClass::getRemoveClasses();

            foreach ($removeClasses as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    $actionClass = $value;
                    if (! isset($mapping[$actionClass])) {
                        $mapping[$actionClass] = [];
                    }
                    if (! in_array($prefix, $mapping[$actionClass], true)) {
                        $mapping[$actionClass][] = $prefix;
                    }
                } elseif (is_string($key)) {
                    $actionClass = $key;
                    if (! isset($mapping[$actionClass])) {
                        $mapping[$actionClass] = [];
                    }
                    if (! in_array($prefix, $mapping[$actionClass], true)) {
                        $mapping[$actionClass][] = $prefix;
                    }
                    $targetPrefixes = is_array($value) ? $value : [$value];
                    foreach ($targetPrefixes as $targetPrefix) {
                        if (is_string($targetPrefix) && ! in_array($targetPrefix, $mapping[$actionClass], true)) {
                            $mapping[$actionClass][] = $targetPrefix;
                        }
                    }
                }
            }
        }

        return self::$classMappingCache = $mapping;
    }

    public static function generateCacheGroups(): array
    {
        if (self::$groupsCache !== null) {
            return self::$groupsCache;
        }

        $groups = [];

        foreach (self::$groups as $groupClass) {
            $prefix = $groupClass::getPrefix();
            $groups[$prefix] = [
                'class' => $groupClass,
                'config' => $groupClass::getConfig(),
                'cache_classes' => $groupClass::getClasses(),
                'invalidate_classes' => $groupClass::getRemoveClasses(),
            ];
        }

        return self::$groupsCache = $groups;
    }

    public static function getGroupConfig(string $prefix): ?array
    {
        $routes = self::generateRoutesConfig();
        return $routes[$prefix] ?? null;
    }

    public static function getAllPrefixes(): array
    {
        return array_keys(self::generateRoutesConfig());
    }

    public static function getPrefixesForClass(string $actionClass): array
    {
        $mapping = self::generateClassMapping();
        return $mapping[$actionClass] ?? [];
    }

    public static function getRelatedPrefixes(string $prefix): array
    {
        $config = self::getGroupConfig($prefix);
        if ($config === null) {
            return [];
        }
        $related = $config['also_invalidate'] ?? [];
        return array_filter((array) $related, 'is_string');
    }

    public static function getScopeForPrefix(string $prefix): string
    {
        $config = self::getGroupConfig($prefix);
        return $config['scope'] ?? $config['type'] ?? 'global';
    }

    public static function validate(): array
    {
        $errors = [];
        $seenPrefixes = [];

        foreach (self::$groups as $groupClass) {
            $className = is_string($groupClass) ? $groupClass : get_class($groupClass);

            if (! is_subclass_of($groupClass, CacheGroupInterface::class)) {
                $errors[] = "{$className} must implement CacheGroupInterface";
                continue;
            }

            $prefix = $groupClass::getPrefix();
            $config = $groupClass::getConfig();

            if (empty($prefix)) {
                $errors[] = "{$className} has empty prefix";
            }

            if (isset($seenPrefixes[$prefix])) {
                $errors[] = "Duplicate prefix '{$prefix}' in {$className} and {$seenPrefixes[$prefix]}";
            }
            $seenPrefixes[$prefix] = $className;

            if (! isset($config['scope']) && ! isset($config['type'])) {
                $errors[] = "{$className} missing 'scope' in config";
            }
        }

        $circularErrors = self::detectCircularDependencies();
        $errors = array_merge($errors, $circularErrors);

        $allPrefixes = self::getAllPrefixes();
        foreach (self::$groups as $groupClass) {
            $config = $groupClass::getConfig();
            $alsoInvalidate = $config['also_invalidate'] ?? [];
            foreach ((array) $alsoInvalidate as $related) {
                if (is_string($related) && ! in_array($related, $allPrefixes, true)) {
                    $prefix = $groupClass::getPrefix();
                    $errors[] = "'{$prefix}' references unknown prefix '{$related}' in also_invalidate";
                }
            }
        }

        return $errors;
    }

    public static function detectCircularDependencies(): array
    {
        $errors = [];
        $routes = self::generateRoutesConfig();

        foreach ($routes as $prefix => $config) {
            $visited = [];
            $chain = [$prefix];
            self::walkDependencies($prefix, $routes, $visited, $chain, $errors);
        }

        return array_unique($errors);
    }

    protected static function walkDependencies(
        string $prefix,
        array $routes,
        array &$visited,
        array $chain,
        array &$errors
    ): void {
        if (in_array($prefix, $visited, true)) {
            $errors[] = "Circular dependency: " . implode(' → ', $chain);
            return;
        }

        $visited[] = $prefix;
        $config = $routes[$prefix] ?? [];
        $related = (array) ($config['also_invalidate'] ?? []);

        foreach ($related as $relatedPrefix) {
            if (! is_string($relatedPrefix)) {
                continue;
            }
            $newChain = array_merge($chain, [$relatedPrefix]);
            self::walkDependencies($relatedPrefix, $routes, $visited, $newChain, $errors);
        }
    }

    public static function clearCache(): void
    {
        self::$routesCache = null;
        self::$classMappingCache = null;
        self::$groupsCache = null;
    }

    public static function reset(): void
    {
        self::$groups = [];
        self::$flatConfigs = [];
        self::$flatClassMapping = [];
        self::clearCache();
    }
}
