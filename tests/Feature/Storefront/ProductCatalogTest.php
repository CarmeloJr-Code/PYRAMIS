<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an active product with one available size.
     */
    protected function product(string $name, ?Category $category = null): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'category_id' => $category?->id ?? Category::factory(),
        ]);

        ProductVariant::factory()->for($product)->create([
            'name' => 'Round',
            'price' => '460.00',
        ]);

        return $product;
    }

    public function test_a_guest_can_browse_the_catalogue(): void
    {
        $this->product('Ube Custard Cake');

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeText('Ube Custard Cake')
            ->assertSeeText('460.00');
    }

    public function test_the_home_page_lists_categories_that_have_products(): void
    {
        $stocked = Category::factory()->create(['name' => 'Ube Cakes']);
        Category::factory()->create(['name' => 'Empty Shelf']);

        $this->product('Ube Cake', $stocked);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('Ube Cakes')
            ->assertDontSeeText('Empty Shelf');
    }

    public function test_a_guest_can_open_a_product_and_see_every_size(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake', 'slug' => 'ube-cake']);
        ProductVariant::factory()->for($product)->create(['name' => 'Large', 'price' => '990.00']);
        ProductVariant::factory()->for($product)->create(['name' => 'Medium', 'price' => '660.00']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSeeText('Ube Cake - '.config('app.name'))
            ->assertSeeText('Large')
            ->assertSeeText('990.00')
            ->assertSeeText('Medium')
            ->assertSeeText('660.00');
    }

    public function test_an_inactive_product_is_hidden_from_the_catalogue_and_not_reachable(): void
    {
        $product = Product::factory()->inactive()->create([
            'name' => 'Withdrawn Cake',
            'slug' => 'withdrawn-cake',
        ]);
        ProductVariant::factory()->for($product)->create(['name' => 'Round', 'price' => '100.00']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertDontSeeText('Withdrawn Cake');

        $this->get(route('products.show', $product))->assertNotFound();
    }

    public function test_a_sold_out_product_stays_visible_and_is_marked(): void
    {
        $product = Product::factory()->create(['name' => 'Chobe Cake', 'slug' => 'chobe-cake']);
        ProductVariant::factory()->for($product)->unavailable()->create([
            'name' => 'Tincan',
            'price' => '340.00',
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeText('Chobe Cake')
            ->assertSeeText('Sold out');

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSeeText('Tincan')
            ->assertSeeText('Sold out');
    }

    public function test_the_category_filter_narrows_the_catalogue(): void
    {
        $bars = Category::factory()->create(['name' => 'Bars', 'slug' => 'bars']);
        $cakes = Category::factory()->create(['name' => 'Cakes', 'slug' => 'cakes']);

        $this->product('Ube Calamansi Bar', $bars);
        $this->product('Ube Cake', $cakes);

        $this->get(route('products.index', ['category' => 'bars']))
            ->assertOk()
            ->assertSeeText('Ube Calamansi Bar')
            ->assertDontSeeText('Ube Cake');
    }

    public function test_the_storefront_offers_the_employee_sign_in_entry_point(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('login'))
            ->assertSeeText('Employee sign in');
    }

    public function test_the_storefront_never_exposes_the_employee_workspace(): void
    {
        // BR-012 — customer-facing pages must not leak employee-only surfaces.
        $this->product('Ube Cake');

        $response = $this->get(route('products.index'));

        $response->assertOk()
            ->assertDontSee(route('employee.products.index'))
            ->assertDontSee(route('employee.dashboard'));
    }
}
