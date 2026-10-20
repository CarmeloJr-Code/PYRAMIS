<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontAppearanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The storefront pages hold themselves light whatever the device prefers,
     * so the order form never renders white text on its white cards.
     */
    public function test_storefront_pages_force_the_light_appearance(): void
    {
        foreach (['home', 'products.index', 'order'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('data-storefront-appearance', false)
                ->assertSee('data-storefront>', false);
        }
    }

    /**
     * The workspace keeps following the employee's chosen appearance.
     */
    public function test_the_workspace_is_left_to_its_own_appearance(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-storefront-appearance', false);
    }
}
