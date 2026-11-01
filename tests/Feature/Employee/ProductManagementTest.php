<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalogue routes, which are Administrator-only.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.products.index'],
            'create' => ['employee.products.create'],
            'categories' => ['employee.products.categories'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_may_open_the_catalogue(string $route): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($route));

            $role === UserRole::Administrator
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    #[DataProvider('routeProvider')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_non_administrators_cannot_open_the_edit_page(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.products.edit', $product))
            ->assertForbidden();
    }

    public function test_an_administrator_creates_a_product_with_its_variants(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Custard Cake')
            ->set('category_id', $category->id)
            ->set('description', 'Ube base with a custard top.')
            ->set('variants', [
                ['id' => null, 'name' => 'Round', 'price' => '460.00', 'is_available' => true],
                ['id' => null, 'name' => 'Slice', 'price' => '70.00', 'is_available' => false],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::firstWhere('name', 'Ube Custard Cake');

        $this->assertNotNull($product);
        $this->assertSame('ube-custard-cake', $product->slug);
        $this->assertSame($category->id, $product->category_id);
        $this->assertTrue($product->is_active);
        $this->assertCount(2, $product->variants);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'name' => 'Round',
            'price' => '460.00',
            'is_available' => true,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'name' => 'Slice',
            'is_available' => false,
        ]);
    }

    public function test_editing_updates_kept_variants_and_deletes_removed_ones(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake']);
        $kept = ProductVariant::factory()->for($product)->create(['name' => 'Round (7x3)', 'price' => '500.00']);
        $removed = ProductVariant::factory()->for($product)->create(['name' => 'Slice', 'price' => '70.00']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage', ['product' => $product])
            ->set('variants', [
                ['id' => $kept->id, 'name' => 'Round (7x3)', 'price' => '520.00', 'is_available' => true],
                ['id' => null, 'name' => 'Heart (6" dia)', 'price' => '500.00', 'is_available' => true],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'price' => '520.00']);
        $this->assertDatabaseMissing('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'name' => 'Heart (6" dia)']);
        $this->assertSame(2, $product->fresh()->variants()->count());
    }

    public function test_it_rejects_a_product_with_no_name_or_category(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', '')
            ->set('category_id', null)
            ->call('save')
            ->assertHasErrors(['name', 'category_id']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_it_rejects_a_duplicate_product_name(): void
    {
        Product::factory()->create(['name' => 'Ube Cake']);
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [['id' => null, 'name' => 'Round', 'price' => '500.00', 'is_available' => true]])
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertSame(1, Product::where('name', 'Ube Cake')->count());
    }

    public function test_it_rejects_a_negative_price(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [['id' => null, 'name' => 'Round', 'price' => '-1', 'is_available' => true]])
            ->call('save')
            ->assertHasErrors(['variants.0.price']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_it_rejects_two_variants_sharing_a_name(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [
                ['id' => null, 'name' => 'Round', 'price' => '500.00', 'is_available' => true],
                ['id' => null, 'name' => 'Round', 'price' => '300.00', 'is_available' => true],
            ])
            ->call('save')
            ->assertHasErrors(['variants.0.name']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_a_product_must_keep_at_least_one_variant(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [])
            ->call('save')
            ->assertHasErrors(['variants']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_an_administrator_can_deactivate_and_reactivate_a_product(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.index')
            ->call('toggleActive', $product->id);

        $this->assertFalse($product->fresh()->is_active);

        $component->call('toggleActive', $product->id);

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_the_component_itself_refuses_non_administrators(): void
    {
        // The `can:` route middleware is not the only guard — mounting the
        // component directly, as a Livewire update request does, is refused too.
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.products.index')
            ->assertForbidden();
    }

    public function test_it_rejects_a_price_finer_than_a_centavo(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [['id' => null, 'name' => 'Round', 'price' => '460.005', 'is_available' => true]])
            ->call('save')
            ->assertHasErrors(['variants.0.price' => 'decimal']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_names_are_stored_without_surrounding_spaces(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', ' Ube Cake  ')
            ->set('category_id', $category->id)
            ->set('variants', [['id' => null, 'name' => ' Round ', 'price' => '460.00', 'is_available' => true]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Ube Cake']);
        $this->assertDatabaseHas('product_variants', ['name' => 'Round']);
    }

    public function test_two_sizes_differing_only_by_a_space_are_the_same_size(): void
    {
        $category = Category::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->set('name', 'Ube Cake')
            ->set('category_id', $category->id)
            ->set('variants', [
                ['id' => null, 'name' => 'Round', 'price' => '460.00', 'is_available' => true],
                ['id' => null, 'name' => 'Round ', 'price' => '480.00', 'is_available' => true],
            ])
            ->call('save')
            ->assertHasErrors(['variants.0.name' => 'distinct']);

        $this->assertDatabaseEmpty('products');
    }

    public function test_the_price_field_refuses_what_is_not_a_price(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->assertSeeHtml('max="9999999.99"')
            ->assertSeeHtml('inputmode="decimal"')
            ->assertSeeHtml('maxlength="1000"');
    }

    public function test_a_product_nothing_has_happened_to_is_deleted_with_its_sizes_and_recipes(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $recipe = Recipe::factory()->for($variant, 'productVariant')->create();
        RecipeItem::factory()->for($recipe)->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.index')
            ->assertSeeHtml('wire:confirm')
            ->call('delete', $product->id)
            ->assertDontSee($product->name);

        $this->assertModelMissing($product);
        $this->assertModelMissing($variant);
        $this->assertModelMissing($recipe);
        $this->assertDatabaseEmpty('recipe_items');
    }

    /**
     * @param  class-string<OrderItem|SaleItem>  $line
     */
    #[TestWith([OrderItem::class])]
    #[TestWith([SaleItem::class])]
    public function test_a_product_with_a_past_is_kept_and_left_to_be_deactivated(string $line): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $sold = ProductVariant::factory()->for($product)->create();
        ProductVariant::factory()->for($product)->create();

        $line::factory()->create(['product_variant_id' => $sold->id]);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.index')
            ->call('delete', $product->id)
            ->assertSee($product->name);

        $this->assertModelExists($product);
        $this->assertSame(2, $product->variants()->count());
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_an_administrator_adds_a_category_a_product_can_then_be_filed_under(): void
    {
        $administrator = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.products.categories')
            ->set('name', '  Holiday Specials ')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('name', '')
            ->assertSee('Holiday Specials');

        $category = Category::firstWhere('name', 'Holiday Specials');

        $this->assertNotNull($category);
        $this->assertSame('holiday-specials', $category->slug);

        Livewire::actingAs($administrator)
            ->test('pages::employee.products.manage')
            ->assertSee('Holiday Specials')
            ->set('name', 'Ube Fruitcake')
            ->set('category_id', $category->id)
            ->set('variants', [['id' => null, 'name' => 'Loaf', 'price' => '650.00', 'is_available' => true]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($category->id, Product::firstWhere('name', 'Ube Fruitcake')->category_id);
    }

    #[TestWith(['', 'required'])]
    #[TestWith(['   ', 'required'])]
    #[TestWith(['A', 'min'])]
    #[TestWith([' Cakes ', 'unique'])]
    public function test_a_category_that_is_missing_too_short_or_already_there_is_refused(string $name, string $rule): void
    {
        Category::factory()->create(['name' => 'Cakes', 'slug' => 'cakes']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.categories')
            ->set('name', $name)
            ->call('add')
            ->assertHasErrors(['name' => $rule]);

        $this->assertSame(1, Category::count());
    }

    public function test_a_name_whose_slug_is_taken_gets_its_own(): void
    {
        Category::factory()->create(['name' => 'Cakes', 'slug' => 'cakes']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.categories')
            ->set('name', 'Cakes!')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame('cakes-2', Category::firstWhere('name', 'Cakes!')->slug);
    }

    public function test_the_list_counts_the_products_in_each_category(): void
    {
        $cakes = Category::factory()->create(['name' => 'Cakes']);
        Product::factory()->count(2)->for($cakes)->create();
        Category::factory()->create(['name' => 'Breads']);

        $categories = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.categories')
            ->instance()
            ->categories()
            ->keyBy('name');

        $this->assertSame(2, $categories['Cakes']->products_count);
        $this->assertSame(0, $categories['Breads']->products_count);
    }

    public function test_the_component_itself_refuses_non_administrators_adding_a_category(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.products.categories')
            ->assertForbidden();

        $this->assertSame(0, Category::count());
    }
}
