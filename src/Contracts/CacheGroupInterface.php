<?php

namespace Ebects\LaravelCacheGroup\Contracts;

/**
 * CacheGroupInterface
 *
 * Every cache group must implement this interface.
 * A cache group represents a logical unit of cache (e.g. "statistics", "inbox", "master.data").
 */
interface CacheGroupInterface
{
    /**
     * Get the cache prefix for this group.
     *
     * @return string e.g. 'statistics', 'inbox.mail', 'master.derajat'
     */
    public static function getPrefix(): string;

    /**
     * Get the configuration for this group.
     *
     * Required keys:
     * - 'scope': string — 'global' or any custom scope name ('user', 'role', 'tenant')
     * - 'ttl': int — cache TTL in seconds
     *
     * Optional keys:
     * - 'also_invalidate': array — list of related prefixes to cascade invalidate
     * - 'on_missing_context': string — override global behavior ('throw', 'skip', 'invalidate_all')
     * - 'throttle': array — override throttle settings for this group
     *
     * @return array
     */
    public static function getConfig(): array;

    /**
     * Get the list of action classes that WRITE to this cache (cache readers).
     *
     * These are the classes that use Cache::remember() or similar.
     *
     * @return array<class-string>
     */
    public static function getClasses(): array;

    /**
     * Get the list of action classes that should INVALIDATE this cache.
     *
     * Supports two formats:
     *
     * Simple (action invalidates this group's prefix):
     *   [App\Actions\CreatePost::class]
     *
     * Associative (action also invalidates other prefixes):
     *   [App\Actions\CreatePost::class => ['other.prefix', 'another.prefix']]
     *
     * @return array
     */
    public static function getRemoveClasses(): array;
}
