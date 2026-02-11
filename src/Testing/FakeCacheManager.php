<?php

namespace Ebects\LaravelCacheGroup\Testing;

use Ebects\LaravelCacheGroup\CacheManager;
use Ebects\LaravelCacheGroup\Contracts\InvalidationStrategy;
use Ebects\LaravelCacheGroup\Contracts\ScopeResolver;
use PHPUnit\Framework\Assert;

/**
 * FakeCacheManager
 *
 * A test double for CacheManager that records all invalidation calls
 * instead of actually deleting cache keys.
 *
 * @example
 * // In your test:
 * $fake = CacheGroup::fake();
 *
 * // ... run your code ...
 *
 * $fake->assertInvalidated('statistics');
 * $fake->assertInvalidatedFor('statistics', 'user', 'user-123');
 * $fake->assertNotInvalidated('unrelated.prefix');
 */
class FakeCacheManager extends CacheManager
{
    /**
     * Recorded invalidation calls.
     *
     * @var array<array{prefix: string, scope: string, identifier: ?string, type: string}>
     */
    protected array $recorded = [];

    public function __construct()
    {
        // Use null resolver and strategy — we're faking everything
        parent::__construct(
            new FakeScopeResolver(),
            new FakeInvalidationStrategy(),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate(string $prefix): int
    {
        $this->recorded[] = [
            'prefix' => $prefix,
            'scope' => 'auto',
            'identifier' => null,
            'type' => 'invalidate',
        ];

        return 1;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateFor(string $prefix, string $scope, mixed $target): int
    {
        $identifier = is_string($target) ? $target : (is_object($target) ? get_class($target) : (string) $target);

        $this->recorded[] = [
            'prefix' => $prefix,
            'scope' => $scope,
            'identifier' => $identifier,
            'type' => 'invalidateFor',
        ];

        return 1;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateAll(string $prefix): int
    {
        $this->recorded[] = [
            'prefix' => $prefix,
            'scope' => '*',
            'identifier' => '*',
            'type' => 'invalidateAll',
        ];

        return 1;
    }

    /**
     * {@inheritdoc}
     */
    public function forceInvalidate(string $prefix, ?string $throttleKey = null): array
    {
        $this->recorded[] = [
            'prefix' => $prefix,
            'scope' => 'force',
            'identifier' => $throttleKey,
            'type' => 'forceInvalidate',
        ];

        return ['invalidated' => true, 'throttled' => false, 'retry_after' => null];
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateRelated(string $prefix, array $visited = []): int
    {
        $this->recorded[] = [
            'prefix' => $prefix,
            'scope' => 'related',
            'identifier' => null,
            'type' => 'invalidateRelated',
        ];

        return 1;
    }

    // ========================================
    // Assertion Methods
    // ========================================

    /**
     * Assert that a prefix was invalidated.
     */
    public function assertInvalidated(string $prefix, ?string $message = null): void
    {
        $found = collect($this->recorded)->contains('prefix', $prefix);

        Assert::assertTrue(
            $found,
            $message ?? "Cache group '{$prefix}' was not invalidated."
        );
    }

    /**
     * Assert that a prefix was invalidated for a specific scope and target.
     */
    public function assertInvalidatedFor(string $prefix, string $scope, string $identifier, ?string $message = null): void
    {
        $found = collect($this->recorded)->contains(function ($record) use ($prefix, $scope, $identifier) {
            return $record['prefix'] === $prefix
                && $record['scope'] === $scope
                && $record['identifier'] === $identifier;
        });

        Assert::assertTrue(
            $found,
            $message ?? "Cache group '{$prefix}' was not invalidated for scope '{$scope}' with identifier '{$identifier}'."
        );
    }

    /**
     * Assert that a prefix was NOT invalidated.
     */
    public function assertNotInvalidated(string $prefix, ?string $message = null): void
    {
        $found = collect($this->recorded)->contains('prefix', $prefix);

        Assert::assertFalse(
            $found,
            $message ?? "Cache group '{$prefix}' was unexpectedly invalidated."
        );
    }

    /**
     * Assert that invalidateAll was called for a prefix.
     */
    public function assertAllInvalidated(string $prefix, ?string $message = null): void
    {
        $found = collect($this->recorded)->contains(function ($record) use ($prefix) {
            return $record['prefix'] === $prefix && $record['type'] === 'invalidateAll';
        });

        Assert::assertTrue(
            $found,
            $message ?? "Cache group '{$prefix}' did not receive invalidateAll."
        );
    }

    /**
     * Assert total number of invalidation calls.
     */
    public function assertInvalidationCount(int $expected, ?string $message = null): void
    {
        $actual = count($this->recorded);

        Assert::assertEquals(
            $expected,
            $actual,
            $message ?? "Expected {$expected} invalidation calls, got {$actual}."
        );
    }

    /**
     * Assert no invalidations were made.
     */
    public function assertNothingInvalidated(?string $message = null): void
    {
        Assert::assertEmpty(
            $this->recorded,
            $message ?? "Expected no invalidations, but " . count($this->recorded) . " were recorded."
        );
    }

    /**
     * Get all recorded invalidation calls.
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * Reset recorded calls.
     */
    public function reset(): void
    {
        $this->recorded = [];
    }
}

/**
 * Fake ScopeResolver for testing.
 */
class FakeScopeResolver implements ScopeResolver
{
    public function resolve(string $scope): ?string
    {
        return 'test-identifier';
    }

    public function resolveFor(string $scope, mixed $target): ?string
    {
        return is_string($target) ? $target : 'test-identifier';
    }

    public function hasActiveSession(): bool
    {
        return true;
    }
}

/**
 * Fake InvalidationStrategy for testing.
 */
class FakeInvalidationStrategy implements InvalidationStrategy
{
    public function invalidate(string $prefix, string $scope, ?string $identifier = null): int
    {
        return 1;
    }

    public function invalidateAll(string $prefix, string $scope): int
    {
        return 1;
    }

    public function invalidateResource(string $prefix, string $scope = 'global', ?string $identifier = null): int
    {
        return 1;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
