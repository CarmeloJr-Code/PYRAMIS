<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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
}
