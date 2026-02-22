<?php

namespace Ebects\LaravelCacheGroup\Tests\Unit;

use Ebects\LaravelCacheGroup\CacheManager;
use Ebects\LaravelCacheGroup\CacheRegistry;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CircularGroupA;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CircularGroupB;
use Ebects\LaravelCacheGroup\Tests\Fixtures\CreatePost;
use Ebects\LaravelCacheGroup\Tests\Fixtures\GlobalCacheGroup;
use Ebects\LaravelCacheGroup\Tests\Fixtures\StatisticsCacheGroup;
use Ebects\LaravelCacheGroup\Tests\Fixtures\UserScopedCacheGroup;
use Ebects\LaravelCacheGroup\Tests\TestCase;
use Mockery;

class CacheManagerTest extends TestCase
{
    protected InvalidationStrategy|Mockery\MockInterface $strategy;
    protected ScopeResolver|Mockery\MockInterface $scopeResolver;
    protected CacheManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = Mockery::mock(InvalidationStrategy::class);
        $this->scopeResolver = Mockery::mock(ScopeResolver::class);
        $this->manager = new CacheManager($this->scopeResolver, $this->strategy);
    }

    /** @test */
    public function invalidateByClass_triggers_also_invalidate_cascade()
    {
        // UserScopedCacheGroup: prefix='user.dashboard', also_invalidate=['statistics']
        // StatisticsCacheGroup: prefix='statistics', also_invalidate=[]
        // CreatePost is in UserScopedCacheGroup's getRemoveClasses
        CacheRegistry::registerMany([
            UserScopedCacheGroup::class,
            StatisticsCacheGroup::class,
        ]);

        $this->scopeResolver->shouldReceive('hasActiveSession')->andReturn(true);
        $this->scopeResolver->shouldReceive('resolve')->with('user')->andReturn('user-1');

        $invalidatedPrefixes = [];
        $this->strategy->shouldReceive('invalidate')
            ->andReturnUsing(function ($prefix) use (&$invalidatedPrefixes) {
                $invalidatedPrefixes[] = $prefix;
                return 1;
            });
        $this->strategy->shouldReceive('invalidateResource')->andReturn(0);

        $result = $this->manager->invalidateByClass(CreatePost::class);

        // user.dashboard should be invalidated directly
        $this->assertContains('user.dashboard', $invalidatedPrefixes);
        // statistics should be invalidated via also_invalidate cascade
        $this->assertContains('statistics', $invalidatedPrefixes);
        $this->assertGreaterThanOrEqual(2, $result);
    }

    /** @test */
    public function invalidateByClassFor_triggers_also_invalidate_cascade()
    {
        CacheRegistry::registerMany([
            UserScopedCacheGroup::class,
            StatisticsCacheGroup::class,
        ]);

        $this->scopeResolver->shouldReceive('resolveFor')
            ->with('user', 'user-42')
            ->andReturn('user-42');

        $invalidatedPrefixes = [];
        $this->strategy->shouldReceive('invalidate')
            ->andReturnUsing(function ($prefix) use (&$invalidatedPrefixes) {
                $invalidatedPrefixes[] = $prefix;
                return 1;
            });
        $this->strategy->shouldReceive('invalidateResource')->andReturn(0);

        $result = $this->manager->invalidateByClassFor(CreatePost::class, 'user', 'user-42');

        $this->assertContains('user.dashboard', $invalidatedPrefixes);
        $this->assertContains('statistics', $invalidatedPrefixes);
        $this->assertGreaterThanOrEqual(2, $result);
    }

    /** @test */
    public function invalidateRelated_does_not_loop_on_circular_dependencies()
    {
        // CircularGroupA: prefix='circular.a', also_invalidate=['circular.b']
        // CircularGroupB: prefix='circular.b', also_invalidate=['circular.a']
        CacheRegistry::registerMany([
            CircularGroupA::class,
            CircularGroupB::class,
        ]);

        $callCount = 0;
        $this->strategy->shouldReceive('invalidate')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return 1;
            });
        $this->strategy->shouldReceive('invalidateResource')->andReturn(0);

        // Should not infinite loop; circular.b gets invalidated, circular.a is already visited
        $result = $this->manager->invalidateRelated('circular.a');

        // circular.b should be invalidated once, circular.a should not be re-invalidated
        $this->assertEquals(1, $callCount, 'Circular dependency should be invalidated exactly once');
        $this->assertEquals(1, $result);
    }

    /** @test */
    public function invalidateRelatedFor_does_not_loop_on_circular_dependencies()
    {
        CacheRegistry::registerMany([
            CircularGroupA::class,
            CircularGroupB::class,
        ]);

        $callCount = 0;
        $this->strategy->shouldReceive('invalidate')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return 1;
            });
        $this->strategy->shouldReceive('invalidateResource')->andReturn(0);

        $result = $this->manager->invalidateRelatedFor('circular.a', 'global', null);

        $this->assertEquals(1, $callCount);
        $this->assertEquals(1, $result);
    }

    /** @test */
    public function invalidateRelated_returns_zero_when_no_related_prefixes()
    {
        CacheRegistry::registerMany([
            GlobalCacheGroup::class, // master.data, also_invalidate=[]
        ]);

        $this->strategy->shouldNotReceive('invalidate');
        $this->strategy->shouldNotReceive('invalidateResource');

        $result = $this->manager->invalidateRelated('master.data');

        $this->assertEquals(0, $result);
    }

    /** @test */
    public function invalidateByClass_does_not_invalidate_same_prefix_twice()
    {
        CacheRegistry::registerMany([
            UserScopedCacheGroup::class,
            StatisticsCacheGroup::class,
        ]);

        $this->scopeResolver->shouldReceive('hasActiveSession')->andReturn(true);
        $this->scopeResolver->shouldReceive('resolve')->with('user')->andReturn('user-1');

        $invalidatedPrefixes = [];
        $this->strategy->shouldReceive('invalidate')
            ->andReturnUsing(function ($prefix) use (&$invalidatedPrefixes) {
                $invalidatedPrefixes[] = $prefix;
                return 1;
            });
        $this->strategy->shouldReceive('invalidateResource')->andReturn(0);

        $this->manager->invalidateByClass(CreatePost::class);

        // Each prefix should appear exactly once
        $uniquePrefixes = array_unique($invalidatedPrefixes);
        $this->assertCount(count($uniquePrefixes), $invalidatedPrefixes, 'No prefix should be invalidated twice');
    }
}
