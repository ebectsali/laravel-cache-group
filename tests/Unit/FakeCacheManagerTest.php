<?php

namespace Ebects\LaravelCacheGroup\Tests\Unit;

use Ebects\LaravelCacheGroup\Testing\FakeCacheManager;
use Ebects\LaravelCacheGroup\Tests\TestCase;

class FakeCacheManagerTest extends TestCase
{
    protected FakeCacheManager $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeCacheManager();
    }

    /** @test */
    public function it_records_invalidation_calls()
    {
        $this->fake->invalidate('statistics');

        $this->fake->assertInvalidated('statistics');
        $this->fake->assertNotInvalidated('other.prefix');
    }

    /** @test */
    public function it_records_invalidateFor_calls()
    {
        $this->fake->invalidateFor('statistics', 'user', 'user-123');

        $this->fake->assertInvalidatedFor('statistics', 'user', 'user-123');
    }

    /** @test */
    public function it_records_invalidateAll_calls()
    {
        $this->fake->invalidateAll('master.data');

        $this->fake->assertAllInvalidated('master.data');
    }

    /** @test */
    public function it_asserts_nothing_invalidated()
    {
        $this->fake->assertNothingInvalidated();
    }

    /** @test */
    public function it_counts_invalidation_calls()
    {
        $this->fake->invalidate('prefix.a');
        $this->fake->invalidate('prefix.b');
        $this->fake->invalidateFor('prefix.c', 'user', 'u1');

        $this->fake->assertInvalidationCount(3);
    }

    /** @test */
    public function it_resets_recorded_calls()
    {
        $this->fake->invalidate('statistics');
        $this->fake->reset();

        $this->fake->assertNothingInvalidated();
    }

    /** @test */
    public function it_records_forceInvalidate_calls()
    {
        $result = $this->fake->forceInvalidate('statistics');

        $this->assertTrue($result['invalidated']);
        $this->assertFalse($result['throttled']);
        $this->fake->assertInvalidated('statistics');
    }
}
