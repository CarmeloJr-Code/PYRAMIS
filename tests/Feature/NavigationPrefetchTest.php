<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The links that are on every page start fetching the next one once the
 * pointer rests on them, so most of the server's time is spent before the
 * click rather than after it.
 */
class NavigationPrefetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workspace_sidebar_prefetches_on_hover(): void
    {
        Outlet::factory()->mainBranch()->create();

        $html = $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->getContent();

        foreach (['employee.dashboard', 'employee.products.index', 'employee.orders.index', 'employee.reports.index', 'employee.messages.index', 'profile.edit'] as $name) {
            $this->assertLinkPrefetchesOnHover($html, route($name));
        }
    }

    public function test_the_storefront_header_prefetches_on_hover(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        foreach (['home', 'products.index', 'orders.track', 'order'] as $name) {
            $this->assertLinkPrefetchesOnHover($html, route($name));
        }
    }

    private function assertLinkPrefetchesOnHover(string $html, string $url): void
    {
        $href = preg_quote('href="'.e($url).'"', '/');

        $this->assertMatchesRegularExpression(
            "/<a\\b(?=[^>]*{$href})(?=[^>]*\\swire:navigate\\.hover\\b)[^>]*>/",
            $html,
            "No link to {$url} prefetches on hover.",
        );
    }
}
