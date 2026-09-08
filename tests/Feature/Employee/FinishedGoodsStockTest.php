<?php

namespace Tests\Feature\Employee;

use App\Actions\RecordProductStockMovement;
use App\Enums\IngredientUnit;
use App\Enums\ProductStockMovementType;
use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Database\Seeders\OutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class FinishedGoodsStockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A size the bakery makes.
     */
    private function variant(string $product, string $size): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size]);
    }

    public function test_only_administrators_and_bakers_may_open_the_shelf(): void
    {
        Outlet::factory()->mainBranch()->create();

        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.inventory.finished'));

            $role === UserRole::Cashier
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.inventory.finished'))->assertRedirect(route('login'));
    }

    public function test_a_production_run_puts_its_output_on_the_main_branch_shelf(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $flour = Ingredient::factory()->create(['unit' => IngredientUnit::Kilogram]);
        InventoryMovement::factory()->for($flour)->quantity('50.000')->create();

        $variant = $this->variant('Ube Cake', 'Round (7x3)');
        $recipe = Recipe::factory()->for($variant, 'productVariant')->yielding(2)->create();
        RecipeItem::factory()->for($recipe)->quantity('4.000')->create(['ingredient_id' => $flour->id]);

        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 6)
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(6, $variant->stockAt($mainBranch));

        $this->assertDatabaseHas('product_stock_movements', [
            'product_variant_id' => $variant->id,
            'outlet_id' => $mainBranch->id,
            'type' => ProductStockMovementType::Produced->value,
            'quantity' => 6,
            'recorded_by' => $baker->id,
        ]);

        // The movement points back at the bake it came out of.
        $movement = ProductStockMovement::firstWhere('product_variant_id', $variant->id);
        $this->assertNotNull($movement->production_run_id);
    }

    public function test_a_shelf_shows_only_its_own_location(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
        $variant = $this->variant('Ube Cake', 'Large (10x14)');

        ProductStockMovement::factory()->for($variant, 'productVariant')->for($mainBranch)->quantity(10)->create();
        ProductStockMovement::factory()->for($variant, 'productVariant')->for($kiosk)->quantity(3)->create();

        $this->assertSame(10, $variant->stockAt($mainBranch));
        $this->assertSame(3, $variant->stockAt($kiosk));

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.finished-goods')
            ->assertSet('outletId', $mainBranch->id)
            ->assertSet('onHand', 10)
            ->set('outletId', $kiosk->id)
            ->assertSet('onHand', 3);
    }

    public function test_an_adjustment_corrects_the_count_and_keeps_its_reason(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create();
        $variant = $this->variant('Ube Cake', 'Round (7x3)');

        ProductStockMovement::factory()->for($variant, 'productVariant')->for($mainBranch)->quantity(12)->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.finished-goods')
            ->set('product_variant_id', $variant->id)
            ->set('direction', 'out')
            ->set('quantity', '2')
            ->set('note', 'Tray dropped')
            ->call('adjust')
            ->assertHasNoErrors();

        $this->assertSame(10, $variant->stockAt($mainBranch));

        $this->assertDatabaseHas('product_stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => ProductStockMovementType::Adjustment->value,
            'quantity' => -2,
            'note' => 'Tray dropped',
        ]);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        Outlet::factory()->mainBranch()->create();
        $variant = $this->variant('Ube Cake', 'Round (7x3)');

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.finished-goods')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', '1')
            ->set('note', '')
            ->call('adjust')
            ->assertHasErrors('note');

        $this->assertSame(0, ProductStockMovement::count());
    }

    public function test_a_shelf_cannot_be_taken_below_zero(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create();
        $variant = $this->variant('Ube Cake', 'Round (7x3)');

        ProductStockMovement::factory()->for($variant, 'productVariant')->for($mainBranch)->quantity(3)->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.finished-goods')
            ->set('product_variant_id', $variant->id)
            ->set('direction', 'out')
            ->set('quantity', '4')
            ->set('note', 'Miscounted')
            ->call('adjust')
            ->assertHasErrors('quantity');

        $this->assertSame(3, $variant->stockAt($mainBranch));
    }

    public function test_the_writer_refuses_production_that_would_reduce_stock(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create();
        $variant = $this->variant('Ube Cake', 'Round (7x3)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot reduce stock');

        app(RecordProductStockMovement::class)->handle(
            $variant,
            $mainBranch,
            User::factory()->baker()->create(),
            ProductStockMovementType::Produced,
            -5,
        );
    }

    public function test_the_main_branch_cannot_be_closed(): void
    {
        $mainBranch = Outlet::factory()->mainBranch()->create();
        $kiosk = Outlet::factory()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.outlets.index')
            ->call('toggleActive', $mainBranch->id)
            ->call('toggleActive', $kiosk->id);

        $this->assertTrue($mainBranch->fresh()->is_active);
        $this->assertFalse($kiosk->fresh()->is_active);
    }

    public function test_the_seeded_outlet_is_the_main_branch(): void
    {
        $this->seed(OutletSeeder::class);

        $this->assertTrue(Outlet::mainBranch()->is_main_branch);
        $this->assertSame('Main Branch', Outlet::mainBranch()->name);
    }
}
