<?php

namespace Ebects\LaravelCacheGroup\Tests\Unit;

use Ebects\LaravelCacheGroup\CacheRegistry;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CircularGroupA;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CircularGroupB;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CreatePost;
use Ebects\LaravelCacheGroup\Tests\Fixtures\GlobalCacheGroup;
use Ebects\LaravelCacheGroup\Tests\Fixtures\RoleScopedCacheGroup;
use Ebects\LaravelCacheGroup\Tests\Fixtures\StatisticsCacheGroup;
use Ebects\LaravelCacheGroup\Tests\Fixtures\UpdateMasterData;
use Ebects\LaravelCacheGroup\Tests\Fixtures\UpdatePost;
use Ebects\LaravelCacheGroup\Tests\Fixtures\UpdateSettings;
use Ebects\LaravelCacheGroup\Tests\Fixtures\UserScopedCacheGroup;
use Ebects\LaravelCacheGroup\Tests\TestCase;

class CacheRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CacheRegistry::reset();
    }

    /** @test */
    public function it_registers_a_cache_group()
    {
        $result = CacheRegistry::register(GlobalCacheGroup::class);

        $this->assertTrue($result);
        $this->assertCount(1, CacheRegistry::getRegisteredGroups());
    }

    /** @test */
    public function it_prevents_duplicate_registration()
    {
        CacheRegistry::register(GlobalCacheGroup::class);
        $result = CacheRegistry::register(GlobalCacheGroup::class);

        $this->assertFalse($result);
        $this->assertCount(1, CacheRegistry::getRegisteredGroups());
    }

    /** @test */
    public function it_rejects_invalid_group_class()
    {
        $result = CacheRegistry::register(\stdClass::class);

        $this->assertFalse($result);
        $this->assertCount(0, CacheRegistry::getRegisteredGroups());
    }

    /** @test */
    public function it_registers_many_groups_at_once()
    {
        CacheRegistry::registerMany([
            GlobalCacheGroup::class,
            UserScopedCacheGroup::class,
            RoleScopedCacheGroup::class,
        ]);

        $this->assertCount(3, CacheRegistry::getRegisteredGroups());
    }

    /** @test */
    public function it_generates_routes_config()
    {
        CacheRegistry::register(GlobalCacheGroup::class);
        CacheRegistry::register(UserScopedCacheGroup::class);

        $routes = CacheRegistry::generateRoutesConfig();

        $this->assertArrayHasKey('master.data', $routes);
        $this->assertArrayHasKey('user.dashboard', $routes);
        $this->assertEquals('global', $routes['master.data']['scope']);
        $this->assertEquals('user', $routes['user.dashboard']['scope']);
    }

    /** @test */
    public function it_generates_class_mapping_simple()
    {
        CacheRegistry::register(GlobalCacheGroup::class);

        $mapping = CacheRegistry::generateClassMapping();

        $this->assertArrayHasKey(UpdateMasterData::class, $mapping);
        $this->assertContains('master.data', $mapping[UpdateMasterData::class]);
    }

    /** @test */
    public function it_generates_class_mapping_associative()
    {
        CacheRegistry::register(UserScopedCacheGroup::class);

        $mapping = CacheRegistry::generateClassMapping();

        // CreatePost → simple, only user.dashboard
        $this->assertArrayHasKey(CreatePost::class, $mapping);
        $this->assertContains('user.dashboard', $mapping[CreatePost::class]);

        // UpdatePost → associative, user.dashboard + posts.list
        $this->assertArrayHasKey(UpdatePost::class, $mapping);
        $this->assertContains('user.dashboard', $mapping[UpdatePost::class]);
        $this->assertContains('posts.list', $mapping[UpdatePost::class]);
    }

    /** @test */
    public function it_gets_scope_for_prefix()
    {
        CacheRegistry::register(GlobalCacheGroup::class);
        CacheRegistry::register(UserScopedCacheGroup::class);
        CacheRegistry::register(RoleScopedCacheGroup::class);

        $this->assertEquals('global', CacheRegistry::getScopeForPrefix('master.data'));
        $this->assertEquals('user', CacheRegistry::getScopeForPrefix('user.dashboard'));
        $this->assertEquals('role', CacheRegistry::getScopeForPrefix('admin.settings'));
        $this->assertEquals('global', CacheRegistry::getScopeForPrefix('nonexistent'));
    }

    /** @test */
    public function it_gets_related_prefixes()
    {
        CacheRegistry::register(UserScopedCacheGroup::class);
        CacheRegistry::register(StatisticsCacheGroup::class);

        $related = CacheRegistry::getRelatedPrefixes('user.dashboard');

        $this->assertContains('statistics', $related);
    }

    /** @test */
    public function it_detects_circular_dependencies()
    {
        CacheRegistry::register(CircularGroupA::class);
        CacheRegistry::register(CircularGroupB::class);

        $errors = CacheRegistry::detectCircularDependencies();

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn($e) => str_contains($e, 'Circular')),
            'Should detect circular dependency between A and B'
        );
    }

    /** @test */
    public function it_validates_groups_with_no_errors()
    {
        CacheRegistry::register(GlobalCacheGroup::class);
        CacheRegistry::register(StatisticsCacheGroup::class);

        $errors = CacheRegistry::validate();

        $this->assertEmpty($errors, 'Expected no validation errors: ' . implode(', ', $errors));
    }

    /** @test */
    public function it_returns_all_prefixes()
    {
        CacheRegistry::registerMany([
            GlobalCacheGroup::class,
            UserScopedCacheGroup::class,
            RoleScopedCacheGroup::class,
        ]);

        $prefixes = CacheRegistry::getAllPrefixes();

        $this->assertContains('master.data', $prefixes);
        $this->assertContains('user.dashboard', $prefixes);
        $this->assertContains('admin.settings', $prefixes);
    }

    /** @test */
    public function it_gets_prefixes_for_action_class()
    {
        CacheRegistry::register(UserScopedCacheGroup::class);

        $prefixes = CacheRegistry::getPrefixesForClass(CreatePost::class);

        $this->assertContains('user.dashboard', $prefixes);
    }

    /** @test */
    public function it_caches_generated_config()
    {
        CacheRegistry::register(GlobalCacheGroup::class);

        $first = CacheRegistry::generateRoutesConfig();
        $second = CacheRegistry::generateRoutesConfig();

        $this->assertSame($first, $second);
    }

    /** @test */
    public function it_clears_cache_on_new_registration()
    {
        CacheRegistry::register(GlobalCacheGroup::class);
        $before = CacheRegistry::getAllPrefixes();

        CacheRegistry::register(UserScopedCacheGroup::class);
        $after = CacheRegistry::getAllPrefixes();

        $this->assertCount(1, $before);
        $this->assertCount(2, $after);
    }
}
