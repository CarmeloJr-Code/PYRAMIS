<?php

namespace Tests\Feature\Employee;

use App\Actions\RecordProductionRun;
use App\Enums\IngredientUnit;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProductionRunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A run puts its output on the main branch's shelf, so every test needs
     * one to exist (BR-003, BR-004).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * An ingredient holding stock a bake can draw on.
     */
    private function stocked(string $name, string $quantity, IngredientUnit $unit = IngredientUnit::Kilogram): Ingredient
    {
        $ingredient = Ingredient::factory()->create(['name' => $name, 'unit' => $unit]);

        InventoryMovement::factory()->for($ingredient)->quantity($quantity)->create();

        return $ingredient;
    }

    /**
     * A size with a recipe: one batch of $yield units, calling for the given
     * per-batch quantities keyed by ingredient id.
     *
     * @param  array<int, string>  $items
     */
    private function producible(string $product, string $size, int $yield, array $items): ProductVariant
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size]);

        $recipe = Recipe::factory()->for($variant, 'productVariant')->yielding($yield)->create();

        foreach ($items as $ingredientId => $quantity) {
            RecipeItem::factory()->for($recipe)->quantity($quantity)->create(['ingredient_id' => $ingredientId]);
        }

        return $variant->fresh();
    }

    public function test_only_administrators_and_bakers_may_open_the_run_log(): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.production.runs.index'));

            $role === UserRole::Cashier
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.production.runs.index'))->assertRedirect(route('login'));
    }

    public function test_a_cashier_cannot_open_a_run(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $variant = $this->producible('Ube Cake', 'Round (7x3)', 2, [$flour->id => '4.000']);

        $run = app(RecordProductionRun::class)->handle($variant, User::factory()->baker()->create(), 2);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.production.runs.show', $run))
            ->assertForbidden();
    }

    public function test_logging_a_run_consumes_its_ingredients_and_records_the_output(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $eggs = $this->stocked('Extra Large Eggs', '120.000', IngredientUnit::Piece);
        $variant = $this->producible('Ube Custard Cake', 'Round', 7, [
            $flour->id => '7.000',
            $eggs->id => '21.000',
        ]);
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 14)
            ->set('notes', 'Morning bake')
            ->call('record')
            ->assertHasNoErrors();

        $run = ProductionRun::firstWhere('product_variant_id', $variant->id);

        $this->assertNotNull($run);
        $this->assertSame(14, $run->quantity);
        $this->assertSame($baker->id, $run->recorded_by);
        $this->assertSame('Morning bake', $run->notes);
        $this->assertStringStartsWith('PD-', $run->reference);

        // Two batches: twice what the recipe writes down.
        $this->assertSame(36000, $flour->fresh()->stockInThousandths());
        $this->assertSame(78000, $eggs->fresh()->stockInThousandths());

        $this->assertSame(2, $run->movements()->count());

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $flour->id,
            'production_run_id' => $run->id,
            'type' => InventoryMovementType::Usage->value,
            'quantity' => '-14.000',
        ]);
    }

    public function test_a_run_the_stockroom_cannot_cover_changes_nothing(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $sugar = $this->stocked('White Sugar', '1.000');
        $variant = $this->producible('Ube Cake', 'Large (10x14)', 1, [
            $flour->id => '2.000',
            $sugar->id => '3.000',
        ]);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 1)
            ->call('record')
            ->assertHasErrors('quantity');

        // The flour line would have gone through on its own; the run is one
        // transaction, so neither ingredient moved and no run was logged.
        $this->assertSame(50000, $flour->fresh()->stockInThousandths());
        $this->assertSame(1000, $sugar->fresh()->stockInThousandths());
        $this->assertSame(0, ProductionRun::count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Usage)->count());
    }

    public function test_a_size_without_a_recipe_cannot_be_produced(): void
    {
        $variant = ProductVariant::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 1)
            ->call('record')
            ->assertHasErrors('product_variant_id');

        $this->assertSame(0, ProductionRun::count());
    }

    public function test_the_action_refuses_a_size_without_a_recipe(): void
    {
        $variant = ProductVariant::factory()->create(['name' => 'Tote Bag']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tote Bag has no recipe');

        app(RecordProductionRun::class)->handle($variant, User::factory()->baker()->create(), 1);
    }

    public function test_a_run_of_nothing_is_refused(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $variant = $this->producible('Ube Cake', 'Round (7x3)', 1, [$flour->id => '1.000']);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 0)
            ->call('record')
            ->assertHasErrors('quantity');

        $this->assertSame(50000, $flour->fresh()->stockInThousandths());
    }

    public function test_a_part_batch_scales_what_it_consumes(): void
    {
        $premix = $this->stocked('Purple Yam Wet Premix', '1000.000', IngredientUnit::Gram);
        $variant = $this->producible('Ube Custard Cake', 'Round', 7, [$premix->id => '450.000']);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 1)
            ->call('record')
            ->assertHasNoErrors();

        // 450 g over seven units, rounded to the thousandth the ledger holds.
        $this->assertSame(1000000 - 64286, $premix->fresh()->stockInThousandths());
    }

    public function test_what_a_run_used_survives_a_later_change_to_its_recipe(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '80.000');
        $variant = $this->producible('Ube Cake', 'Medium (7x10)', 1, [$flour->id => '5.000']);
        $baker = User::factory()->baker()->create();

        $run = app(RecordProductionRun::class)->handle($variant, $baker, 2);

        // The recipe is rewritten afterwards — twice the flour.
        $variant->recipe->items()->update(['quantity' => '10.000']);

        $this->assertSame(70000, $flour->fresh()->stockInThousandths());
        $this->assertSame(
            -10000,
            $run->movements()->first()->quantityInThousandths(),
        );

        Livewire::actingAs($baker)
            ->test('pages::employee.production.runs.show', ['productionRun' => $run])
            ->assertSee('All-Purpose Flour')
            ->assertSee('10');
    }

    public function test_the_preview_shows_what_a_run_would_take_and_flags_a_shortfall(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $sugar = $this->stocked('White Sugar', '1.000');
        $variant = $this->producible('Ube Cake', 'Large (10x14)', 2, [
            $flour->id => '6.000',
            $sugar->id => '2.000',
        ]);

        $component = Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 2)
            ->assertSet('canCover', false);

        $preview = $component->instance()->preview()->keyBy(fn (array $row): string => $row['ingredient']->name);

        $this->assertSame('6', $preview['All-Purpose Flour']['required']);
        $this->assertFalse($preview['All-Purpose Flour']['short']);
        $this->assertTrue($preview['White Sugar']['short']);
    }

    public function test_the_day_log_totals_output_and_ignores_other_days(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '500.000');
        $variant = $this->producible('Ube Cake', 'Round (7x3)', 1, [$flour->id => '1.000']);
        $baker = User::factory()->baker()->create();

        ProductionRun::factory()->for($variant, 'productVariant')->quantity(4)->create([
            'recipe_id' => $variant->recipe->id,
            'recorded_by' => $baker->id,
            'produced_at' => now()->setTime(6, 0),
        ]);

        ProductionRun::factory()->for($variant, 'productVariant')->quantity(3)->create([
            'recipe_id' => $variant->recipe->id,
            'recorded_by' => $baker->id,
            'produced_at' => now()->setTime(13, 0),
        ]);

        ProductionRun::factory()->for($variant, 'productVariant')->quantity(9)->create([
            'recipe_id' => $variant->recipe->id,
            'recorded_by' => $baker->id,
            'produced_at' => now()->subDay()->setTime(9, 0),
        ]);

        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.production.runs.index');

        $output = $component->instance()->output();

        $this->assertCount(1, $output);
        $this->assertSame(7, $output->first()['units']);
        $this->assertCount(2, $component->instance()->runs());
    }
}
