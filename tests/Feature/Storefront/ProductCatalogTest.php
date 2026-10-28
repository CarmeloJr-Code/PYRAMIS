<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_every_size_gets_its_own_card_on_the_menu(): void
    {
        $product = Product::factory()->create(['name' => 'Chocolate Cake', 'slug' => 'chocolate-cake']);
        $small = ProductVariant::factory()->for($product)->create(['name' => 'Small', 'price' => '350.00']);
        $large = ProductVariant::factory()->for($product)->create(['name' => 'Large', 'price' => '900.00']);
        $tincan = ProductVariant::factory()->for($product)->unavailable()->create(['name' => 'Tincan', 'price' => '120.00']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeHtmlInOrder([
                'href="'.route('products.show', ['product' => $product, 'size' => $tincan->id]).'"', 'Chocolate Cake', 'Tincan', '120.00', 'Sold out',
                'href="'.route('products.show', ['product' => $product, 'size' => $small->id]).'"', 'Chocolate Cake', 'Small', '350.00',
                'href="'.route('products.show', ['product' => $product, 'size' => $large->id]).'"', 'Chocolate Cake', 'Large', '900.00',
            ])
            ->assertDontSeeText('from');
    }

    public function test_a_size_card_opens_the_product_with_that_size_chosen(): void
    {
        $product = Product::factory()->create(['name' => 'Chocolate Cake', 'slug' => 'chocolate-cake']);
        $small = ProductVariant::factory()->for($product)->create(['name' => 'Small']);
        $large = ProductVariant::factory()->for($product)->create(['name' => 'Large']);

        // Asked for both ways round, so neither can pass by being the default.
        foreach ([$small, $large] as $size) {
            Livewire::withQueryParams(['size' => $size->id])
                ->test('pages::storefront.product', ['product' => $product])
                ->assertSet('selectedVariantId', $size->id);
        }

        Livewire::withQueryParams(['size' => $large->id])
            ->test('pages::storefront.product', ['product' => $product])
            ->call('addToOrder');

        $this->assertSame([$large->id => 1], session('cart'));
    }

    public function test_a_size_that_cannot_be_sold_or_belongs_elsewhere_falls_back_to_the_first_available(): void
    {
        $product = Product::factory()->create(['name' => 'Chocolate Cake', 'slug' => 'chocolate-cake']);
        $round = ProductVariant::factory()->for($product)->create(['name' => 'Round']);
        $tincan = ProductVariant::factory()->for($product)->unavailable()->create(['name' => 'Tincan']);
        $elsewhere = ProductVariant::factory()->create();

        foreach ([$tincan->id, $elsewhere->id, 'abc'] as $size) {
            Livewire::withQueryParams(['size' => $size])
                ->test('pages::storefront.product', ['product' => $product])
                ->assertSet('selectedVariantId', $round->id);
        }
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

    public function test_the_home_page_shows_each_categorys_illustration(): void
    {
        $this->product('Ube Cake', Category::factory()->create(['name' => 'Ube Cakes', 'slug' => 'ube-cakes']));
        $this->product('Seasonal Roll', Category::factory()->create(['name' => 'Seasonal', 'slug' => 'seasonal']));

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(asset('images/categories/ube-cakes.svg'), false)
            ->assertSee(asset('images/product.svg'), false)
            ->assertDontSee('images/categories/seasonal.svg', false);
    }

    public function test_every_category_on_the_menu_board_has_its_own_illustration(): void
    {
        $this->seed(CatalogSeeder::class);

        Category::all()->each(function (Category $category): void {
            $this->assertSame(asset("images/categories/{$category->slug}.svg"), $category->imageUrl(), $category->name);
        });
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

    public function test_a_product_shows_its_own_illustration(): void
    {
        $product = $this->product('Ube Cake');

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(asset('images/products/ube-cake.svg'), false);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSeeInOrder([asset('images/products/ube-cake.svg'), '<h1 class=', 'Ube Cake</h1>'], false);
    }

    public function test_a_product_without_its_own_illustration_borrows_its_categorys(): void
    {
        $bars = Category::factory()->create(['name' => 'Bars', 'slug' => 'bars']);

        $this->product('Ube Langka Bar', $bars);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(asset('images/categories/bars.svg'), false)
            ->assertDontSee('images/products/ube-langka-bar.svg', false);
    }

    public function test_a_product_with_no_illustration_anywhere_falls_back_to_the_generic_one(): void
    {
        $shelf = Category::factory()->create(['name' => 'Seasonal', 'slug' => 'seasonal']);

        $product = $this->product('Ube Langka Bar', $shelf);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(asset('images/product.svg'), false);
    }

    public function test_every_product_on_the_menu_board_has_its_own_illustration(): void
    {
        $this->seed(CatalogSeeder::class);

        Product::with('category')->get()->each(function (Product $product): void {
            $this->assertSame(asset("images/products/{$product->slug}.svg"), $product->imageUrl(), $product->name);
        });
    }

    public function test_the_first_available_size_is_chosen_without_a_round_trip(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake', 'slug' => 'ube-cake']);
        ProductVariant::factory()->for($product)->unavailable()->create(['name' => 'Tincan']);
        $round = ProductVariant::factory()->for($product)->create(['name' => 'Round']);

        Livewire::test('pages::storefront.product', ['product' => $product])
            ->assertSet('selectedVariantId', $round->id)
            ->assertSeeHtmlInOrder(['value="'.$round->id.'"', 'checked', 'Round'])
            ->assertDontSeeHtml('wire:model.live="selectedVariantId"');
    }

    public function test_the_chosen_size_is_what_goes_in_the_cart(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake', 'slug' => 'ube-cake']);
        ProductVariant::factory()->for($product)->create(['name' => 'Round']);
        $large = ProductVariant::factory()->for($product)->create(['name' => 'Large']);

        Livewire::test('pages::storefront.product', ['product' => $product])
            ->set('selectedVariantId', $large->id)
            ->call('addToOrder')
            ->assertRedirect(route('order'));

        $this->assertSame([$large->id => 1], session('cart'));
    }

    public function test_a_sold_out_size_cannot_be_put_in_the_cart(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake', 'slug' => 'ube-cake']);
        ProductVariant::factory()->for($product)->create(['name' => 'Round']);
        $tincan = ProductVariant::factory()->for($product)->unavailable()->create(['name' => 'Tincan']);

        Livewire::test('pages::storefront.product', ['product' => $product])
            ->set('selectedVariantId', $tincan->id)
            ->call('addToOrder')
            ->assertNoRedirect();

        $this->assertNull(session('cart'));
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
