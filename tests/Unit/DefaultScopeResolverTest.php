<?php

namespace Ebects\LaravelCacheGroup\Tests\Unit;

use Ebects\LaravelCacheGroup\DefaultScopeResolver;
use Ebects\LaravelCacheGroup\Tests\TestCase;
use Illuminate\Foundation\Auth\User as Authenticatable;

class DefaultScopeResolverTest extends TestCase
{
    protected DefaultScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DefaultScopeResolver();
    }

    /** @test */
    public function it_returns_null_for_global_scope()
    {
        $this->assertNull($this->resolver->resolve('global'));
        $this->assertNull($this->resolver->resolveFor('global', 'anything'));
    }

    /** @test */
    public function it_returns_null_when_no_auth()
    {
        $this->assertNull($this->resolver->resolve('user'));
        $this->assertFalse($this->resolver->hasActiveSession());
    }

    /** @test */
    public function it_resolves_string_target_directly()
    {
        $result = $this->resolver->resolveFor('user', 'user-123');

        $this->assertEquals('user-123', $result);
    }

    /** @test */
    public function it_resolves_numeric_target_as_string()
    {
        $result = $this->resolver->resolveFor('user', 42);

        $this->assertEquals('42', $result);
    }

    /** @test */
    public function it_resolves_role_from_user_model()
    {
        $user = new TestUser();
        $user->role = 'admin';

        $result = $this->resolver->resolveFor('role', $user);

        $this->assertEquals('admin', $result);
    }

    /** @test */
    public function it_resolves_custom_scope_from_property_with_id_suffix()
    {
        $user = new TestUser();
        $user->tenant_id = 'org-42';

        $result = $this->resolver->resolveFor('tenant', $user);

        $this->assertEquals('org-42', $result);
    }

    /** @test */
    public function it_resolves_custom_scope_from_direct_property()
    {
        $user = new TestUser();
        $user->department = 'engineering';

        $result = $this->resolver->resolveFor('department', $user);

        $this->assertEquals('engineering', $result);
    }
}

class TestUser extends Authenticatable
{
    protected $guarded = [];
    public $role;
    public $tenant_id;
    public $department;
}
