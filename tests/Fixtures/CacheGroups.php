<?php

namespace Ebects\LaravelCacheGroup\Tests\Fixtures;

use Ebects\LaravelCacheGroup\Contracts\CacheGroupInterface;

class GlobalCacheGroup implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'master.data';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'global',
            'ttl' => 3600,
            'also_invalidate' => [],
        ];
    }

    public static function getClasses(): array
    {
        return [FetchMasterData::class];
    }

    public static function getRemoveClasses(): array
    {
        return [UpdateMasterData::class];
    }
}

class UserScopedCacheGroup implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'user.dashboard';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'user',
            'ttl' => 1800,
            'also_invalidate' => ['statistics'],
        ];
    }

    public static function getClasses(): array
    {
        return [FetchDashboard::class];
    }

    public static function getRemoveClasses(): array
    {
        return [
            CreatePost::class,
            UpdatePost::class => ['posts.list'],
        ];
    }
}

class RoleScopedCacheGroup implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'admin.settings';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'role',
            'ttl' => 7200,
            'also_invalidate' => [],
        ];
    }

    public static function getClasses(): array
    {
        return [FetchSettings::class];
    }

    public static function getRemoveClasses(): array
    {
        return [UpdateSettings::class];
    }
}

class StatisticsCacheGroup implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'statistics';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'user',
            'ttl' => 3600,
            'also_invalidate' => [],
        ];
    }

    public static function getClasses(): array
    {
        return [FetchStatistics::class];
    }

    public static function getRemoveClasses(): array
    {
        return [RefreshStatistics::class];
    }
}

class CircularGroupA implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'circular.a';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'global',
            'ttl' => 3600,
            'also_invalidate' => ['circular.b'],
        ];
    }

    public static function getClasses(): array
    {
        return [];
    }

    public static function getRemoveClasses(): array
    {
        return [];
    }
}

class CircularGroupB implements CacheGroupInterface
{
    public static function getPrefix(): string
    {
        return 'circular.b';
    }

    public static function getConfig(): array
    {
        return [
            'scope' => 'global',
            'ttl' => 3600,
            'also_invalidate' => ['circular.a'],
        ];
    }

    public static function getClasses(): array
    {
        return [];
    }

    public static function getRemoveClasses(): array
    {
        return [];
    }
}

// Dummy action classes for mapping
class FetchMasterData {}
class UpdateMasterData {}
class FetchDashboard {}
class CreatePost {}
class UpdatePost {}
class FetchSettings {}
class UpdateSettings {}
class FetchStatistics {}
class RefreshStatistics {}
