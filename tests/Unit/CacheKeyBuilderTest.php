<?php

namespace Ebects\LaravelCacheGroup\Tests\Unit;

use Ebects\LaravelCacheGroup\CacheKeyBuilder;
use Ebects\LaravelCacheGroup\Tests\TestCase;

class CacheKeyBuilderTest extends TestCase
{
    /** @test */
    public function it_builds_global_cache_key()
    {
        $key = CacheKeyBuilder::buildKey('master.data', 'global', null, 'all');
        $this->assertEquals('test_cache:cache:global:master.data:all', $key);
    }

    /** @test */
    public function it_builds_scoped_cache_key()
    {
        $key = CacheKeyBuilder::buildKey('dashboard', 'user', 'ABC123', 'page_1');
        $this->assertEquals('test_cache:cache:user:user_ABC123:dashboard:page_1', $key);
    }

    /** @test */
    public function it_builds_role_scoped_cache_key()
    {
        $key = CacheKeyBuilder::buildKey('settings', 'role', 'admin', 'modules');
        $this->assertEquals('test_cache:cache:role:role_admin:settings:modules', $key);
    }

    /** @test */
    public function it_builds_custom_scoped_cache_key()
    {
        $key = CacheKeyBuilder::buildKey('reports', 'tenant', 'tenant-42', 'monthly');
        $this->assertEquals('test_cache:cache:tenant:tenant_tenant-42:reports:monthly', $key);
    }

    /** @test */
    public function it_builds_pattern_for_global()
    {
        $pattern = CacheKeyBuilder::buildPattern('master.data', 'global');
        $this->assertEquals('test_cache:cache:global:master.data:*', $pattern);
    }

    /** @test */
    public function it_builds_pattern_for_specific_user()
    {
        $pattern = CacheKeyBuilder::buildPattern('dashboard', 'user', 'ABC123');
        $this->assertEquals('test_cache:cache:user:user_ABC123:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_pattern_for_all_users_wildcard()
    {
        $pattern = CacheKeyBuilder::buildPattern('dashboard', 'user', null);
        $this->assertEquals('test_cache:cache:user:user_*:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_cluster_pattern_with_hash_prefix()
    {
        $pattern = CacheKeyBuilder::buildClusterPattern('dashboard', 'user', 'ABC123');
        $this->assertEquals('*:test_cache:cache:user:user_ABC123:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_global_resource_pattern()
    {
        $pattern = CacheKeyBuilder::buildResourcePattern('dashboard');
        $this->assertEquals('test_cache:resource:global:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_scoped_resource_pattern()
    {
        $pattern = CacheKeyBuilder::buildResourcePattern('dashboard', 'user', 'ABC123');
        $this->assertEquals('test_cache:resource:user:user_ABC123:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_wildcard_resource_pattern_for_all_users()
    {
        $pattern = CacheKeyBuilder::buildResourcePattern('dashboard', 'user', null);
        $this->assertEquals('test_cache:resource:user:user_*:dashboard:*', $pattern);
    }

    /** @test */
    public function it_builds_composite_tags_for_scoped()
    {
        $tags = CacheKeyBuilder::buildTags('dashboard', 'user', 'ABC123');
        $this->assertEquals(['dashboard', 'user_ABC123'], $tags);
    }

    /** @test */
    public function it_builds_single_tag_for_global()
    {
        $tags = CacheKeyBuilder::buildTags('master.data', 'global', null);
        $this->assertEquals(['master.data'], $tags);
    }

    /** @test */
    public function keys_never_contain_double_colons_with_spaces()
    {
        $testCases = [
            ['prefix', 'global', null, 'var'],
            ['prefix', 'user', 'ABC123', 'var'],
            ['prefix', 'role', 'admin', 'var'],
            ['prefix', 'tenant', 'org-1', 'var'],
        ];

        foreach ($testCases as [$group, $scope, $identifier, $variant]) {
            $key = CacheKeyBuilder::buildKey($group, $scope, $identifier, $variant);
            $this->assertDoesNotMatchRegularExpression('/:\s+/', $key, "Key has space after colon: {$key}");
            $this->assertDoesNotMatchRegularExpression('/\s+:/', $key, "Key has space before colon: {$key}");
        }
    }
}
