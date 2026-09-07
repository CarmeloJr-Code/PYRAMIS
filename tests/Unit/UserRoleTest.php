<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_each_role_maps_to_its_portal_design_label(): void
    {
        $this->assertSame('Manager', UserRole::Administrator->label());
        $this->assertSame('Production Staff', UserRole::Baker->label());
        $this->assertSame('Sales Staff', UserRole::Cashier->label());
    }

    public function test_customers_are_not_a_stored_role(): void
    {
        $this->assertNull(UserRole::tryFrom('customer'));
        $this->assertCount(3, UserRole::cases());
    }

    public function test_has_role_matches_any_of_the_given_roles(): void
    {
        $user = new User(['role' => UserRole::Baker]);

        $this->assertTrue($user->hasRole(UserRole::Baker));
        $this->assertTrue($user->hasRole(UserRole::Administrator, UserRole::Baker));
        $this->assertFalse($user->hasRole(UserRole::Administrator, UserRole::Cashier));
    }
}
