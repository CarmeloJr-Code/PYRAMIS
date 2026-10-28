<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MenuSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an active product with the given sizes, all available.
     *
     * @param  list<string>  $sizes
     */
    protected function product(string $name, array $sizes = ['Round'], ?Category $category = null): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'category_id' => $category?->id ?? Category::factory(),
        ]);

        foreach ($sizes as $size) {
            ProductVariant::factory()->for($product)->create(['name' => $size]);
        }

        return $product;
    }

    public function test_the_home_page_offers_a_search_that_lands_on_the_menu(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeHtmlInOrder([
                'action="'.route('products.index').'"',
                'method="get"',
                'role="search"',
                'name="q"',
            ]);
    }

    public function test_a_search_from_the_home_page_narrows_the_menu(): void
    {
        $this->product('Chocolate Cake');
        $this->product('Ube Custard Cake');

        $this->get(route('products.index', ['q' => 'chocolate']))
            ->assertOk()
            ->assertSeeText('Chocolate Cake')
            ->assertDontSeeText('Ube Custard Cake');
    }

    public function test_the_search_ignores_case_and_matches_the_product_size_or_category(): void
    {
        $this->product('Chocolate Cake', ['Small', 'Large']);
        $this->product('Ube Roll', ['Whole'], Category::factory()->create(['name' => 'Rolls']));
        $this->product('Ube Custard Cake', ['Round']);

        Livewire::test('pages::storefront.products')
            ->set('search', 'CHOCO')
            ->assertSeeText('Chocolate Cake')
            ->assertDontSeeText('Ube Custard Cake')
            ->set('search', 'whole')
            ->assertSeeText('Ube Roll')
            ->assertDontSeeText('Chocolate Cake')
            ->set('search', 'rolls')
            ->assertSeeText('Ube Roll')
            ->assertDontSeeText('Ube Custard Cake');
    }

    public function test_every_word_of_the_search_has_to_match(): void
    {
        $chocolate = $this->product('Chocolate Cake', ['Small', 'Large']);
        $small = $chocolate->variants->firstWhere('name', 'Small');
        $large = $chocolate->variants->firstWhere('name', 'Large');
        $custardLarge = $this->product('Ube Custard Cake', ['Large'])->variants->sole();

        Livewire::test('pages::storefront.products')
            ->set('search', 'chocolate large')
            ->assertSeeHtml('wire:key="variant-'.$large->id.'"')
            ->assertDontSeeHtml('wire:key="variant-'.$small->id.'"')
            ->assertDontSeeHtml('wire:key="variant-'.$custardLarge->id.'"');
    }

    public function test_the_search_respects_the_category_filter(): void
    {
        $bars = Category::factory()->create(['name' => 'Bars', 'slug' => 'bars']);
        $cakes = Category::factory()->create(['name' => 'Cakes', 'slug' => 'cakes']);

        $this->product('Ube Calamansi Bar', category: $bars);
        $this->product('Ube Cake', category: $cakes);

        $this->get(route('products.index', ['category' => 'bars', 'q' => 'ube']))
            ->assertOk()
            ->assertSeeText('Ube Calamansi Bar')
            ->assertDontSeeText('Ube Cake');
    }

    public function test_a_search_with_no_match_says_so_and_offers_to_clear_it(): void
    {
        $this->product('Ube Custard Cake');

        Livewire::test('pages::storefront.products')
            ->set('search', 'lasagna')
            ->assertSeeText('Nothing on the menu matches “lasagna”.')
            ->assertSeeText('Clear the search')
            ->assertDontSeeText('Ube Custard Cake')
            ->set('search', '')
            ->assertSeeText('Ube Custard Cake')
            ->assertDontSeeText('Clear the search');
    }

    public function test_a_blank_or_wildcard_only_search_shows_the_whole_menu(): void
    {
        $this->product('Ube Custard Cake');
        $this->product('Chocolate Cake');

        foreach (['   ', '%', '_', '\\'] as $search) {
            Livewire::test('pages::storefront.products')
                ->set('search', $search)
                ->assertSeeText('Ube Custard Cake')
                ->assertSeeText('Chocolate Cake')
                ->assertDontSeeText('Nothing on the menu matches');
        }
    }

    public function test_the_search_never_reaches_a_withdrawn_product(): void
    {
        $withdrawn = Product::factory()->inactive()->create(['name' => 'Withdrawn Cake', 'slug' => 'withdrawn-cake']);
        ProductVariant::factory()->for($withdrawn)->create(['name' => 'Round']);

        $this->get(route('products.index', ['q' => 'withdrawn']))
            ->assertOk()
            ->assertDontSeeText('Withdrawn Cake')
            ->assertSeeText('Nothing on the menu matches “withdrawn”.');
    }

    public function test_the_search_is_kept_in_the_address(): void
    {
        Livewire::withQueryParams(['q' => 'ube'])
            ->test('pages::storefront.products')
            ->assertSet('search', 'ube');
    }
}
