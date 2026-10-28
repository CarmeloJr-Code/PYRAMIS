<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const STOREFRONT = 'shop.test';

    private const WORKSPACE = 'staff.test';

    /**
     * The hosts are read when the routes are registered, so they have to be in
     * the environment before the application boots.
     */
    protected function setUp(): void
    {
        $_SERVER['STOREFRONT_DOMAIN'] = self::STOREFRONT;
        $_SERVER['WORKSPACE_DOMAIN'] = self::WORKSPACE;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['STOREFRONT_DOMAIN'], $_SERVER['WORKSPACE_DOMAIN']);
    }

    public function test_the_storefront_host_serves_the_storefront(): void
    {
        $this->get('http://'.self::STOREFRONT.'/')->assertOk();
        $this->get('http://'.self::STOREFRONT.'/products')->assertOk();
    }

    public function test_the_storefront_host_serves_no_part_of_the_workspace(): void
    {
        $this->get('http://'.self::STOREFRONT.'/employee/login')->assertNotFound();
        $this->get('http://'.self::STOREFRONT.'/employee/dashboard')->assertNotFound();

        $this->actingAs(User::factory()->administrator()->create())
            ->get('http://'.self::STOREFRONT.'/employee/orders')
            ->assertNotFound();
    }

    public function test_the_workspace_host_serves_the_sign_in_and_the_modules(): void
    {
        $this->get('http://'.self::WORKSPACE.'/employee/login')->assertOk();

        $this->get('http://'.self::WORKSPACE.'/employee/dashboard')
            ->assertRedirect('http://'.self::WORKSPACE.'/employee/login');

        $this->actingAs(User::factory()->administrator()->create())
            ->get('http://'.self::WORKSPACE.'/employee/orders')
            ->assertOk();
    }

    public function test_the_workspace_root_opens_the_workspace(): void
    {
        $this->get('http://'.self::WORKSPACE.'/')->assertRedirect('http://'.self::WORKSPACE.'/employee/dashboard');
    }

    public function test_old_storefront_addresses_on_the_workspace_host_move_to_the_storefront(): void
    {
        $this->get('http://'.self::WORKSPACE.'/orders/PY-ABC123')
            ->assertStatus(301)
            ->assertRedirect('http://'.self::STOREFRONT.'/orders/PY-ABC123');

        $this->get('http://'.self::WORKSPACE.'/products/ube-cake?size=large')
            ->assertStatus(301)
            ->assertRedirect('http://'.self::STOREFRONT.'/products/ube-cake?size=large');
    }

    public function test_the_storefront_does_not_link_to_the_workspace_sign_in(): void
    {
        $this->get('http://'.self::STOREFRONT.'/')
            ->assertOk()
            ->assertDontSee('http://'.self::WORKSPACE.'/employee/login', false);
    }

    public function test_the_sign_in_page_links_back_to_the_storefront_host(): void
    {
        $this->get('http://'.self::WORKSPACE.'/employee/login')
            ->assertSee('http://'.self::STOREFRONT, false);
    }
}
