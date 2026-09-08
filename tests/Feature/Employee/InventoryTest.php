<?php

namespace Tests\Feature\Employee;

use App\Enums\IngredientUnit;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The stockroom routes, which the capability matrix gives to the
     * Administrator and the Baker.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.inventory.index'],
            'create' => ['employee.inventory.create'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_and_bakers_may_open_the_stockroom(string $route): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($route));

            $role === UserRole::Cashier
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    #[DataProvider('routeProvider')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_a_cashier_cannot_open_an_ingredient_or_its_form(): void
    {
        $ingredient = Ingredient::factory()->create();
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get(route('employee.inventory.show', $ingredient))->assertForbidden();
        $this->actingAs($cashier)->get(route('employee.inventory.edit', $ingredient))->assertForbidden();
    }

    public function test_a_baker_adds_an_ingredient(): void
    {
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.manage')
            ->set('name', 'Ube Powder')
            ->set('unit', IngredientUnit::Gram->value)
            ->set('reorder_level', '2500')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient = Ingredient::firstWhere('name', 'Ube Powder');

        $this->assertNotNull($ingredient);
        $this->assertSame(IngredientUnit::Gram, $ingredient->unit);
        $this->assertSame('2500.000', $ingredient->reorder_level);
        $this->assertTrue($ingredient->is_active);

        // A new ingredient starts at nothing: stock only exists once a movement
        // says where it came from.
        $this->assertSame(0, $ingredient->stockInThousandths());
    }

    public function test_it_rejects_an_ingredient_with_no_name_or_a_duplicate_one(): void
    {
        Ingredient::factory()->create(['name' => 'White Sugar']);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.manage')
            ->set('name', '')
            ->set('unit', '')
            ->set('reorder_level', '-5')
            ->call('save')
            ->assertHasErrors(['name', 'unit', 'reorder_level']);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.manage')
            ->set('name', 'White Sugar')
            ->set('unit', IngredientUnit::Kilogram->value)
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Ingredient::where('name', 'White Sugar')->count());
    }

    public function test_the_unit_is_fixed_once_the_ledger_has_entries(): void
    {
        $ingredient = Ingredient::factory()->create([
            'name' => 'Cooking Oil',
            'unit' => IngredientUnit::Liter,
        ]);

        InventoryMovement::factory()->for($ingredient)->quantity('20.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.manage', ['ingredient' => $ingredient])
            ->assertSet('unitIsLocked', true)
            // Sent anyway, as a crafted request would.
            ->set('unit', IngredientUnit::Milliliter->value)
            ->set('name', 'Cooking Oil')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(IngredientUnit::Liter, $ingredient->fresh()->unit);
    }

    public function test_recording_a_receipt_raises_stock_and_leaves_a_movement(): void
    {
        $ingredient = Ingredient::factory()->create(['unit' => IngredientUnit::Kilogram]);
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('type', InventoryMovementType::Received->value)
            ->set('quantity', '12.5')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => InventoryMovementType::Received->value,
            'quantity' => '12.500',
            'recorded_by' => $baker->id,
        ]);

        $this->assertSame(12500, $ingredient->fresh()->stockInThousandths());
        $this->assertSame('12.5', $ingredient->fresh()->stock());
    }

    public function test_an_adjustment_can_take_stock_away(): void
    {
        $ingredient = Ingredient::factory()->create();
        InventoryMovement::factory()->for($ingredient)->quantity('30.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('type', InventoryMovementType::Adjustment->value)
            ->set('direction', 'out')
            ->set('quantity', '4.25')
            ->set('note', 'Spoiled in storage')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => InventoryMovementType::Adjustment->value,
            'quantity' => '-4.250',
            'note' => 'Spoiled in storage',
        ]);

        $this->assertSame(25750, $ingredient->fresh()->stockInThousandths());
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('type', InventoryMovementType::Adjustment->value)
            ->set('quantity', '1')
            ->set('note', '')
            ->call('record')
            ->assertHasErrors('note');

        $this->assertSame(0, $ingredient->movements()->count());
    }

    public function test_stock_cannot_be_taken_below_zero(): void
    {
        $ingredient = Ingredient::factory()->create(['unit' => IngredientUnit::Kilogram]);
        InventoryMovement::factory()->for($ingredient)->quantity('5.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('type', InventoryMovementType::Adjustment->value)
            ->set('direction', 'out')
            ->set('quantity', '5.001')
            ->set('note', 'Miscounted')
            ->call('record')
            ->assertHasErrors('quantity');

        $this->assertSame(1, $ingredient->movements()->count());
        $this->assertSame(5000, $ingredient->fresh()->stockInThousandths());
    }

    public function test_a_receipt_never_reduces_stock_however_the_form_is_submitted(): void
    {
        $ingredient = Ingredient::factory()->create();
        InventoryMovement::factory()->for($ingredient)->quantity('10.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('type', InventoryMovementType::Received->value)
            // A crafted request: the direction control is not even drawn for a
            // receipt.
            ->set('direction', 'out')
            ->set('quantity', '3')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(13000, $ingredient->fresh()->stockInThousandths());
    }

    public function test_a_movement_of_nothing_is_refused(): void
    {
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->set('quantity', '0')
            ->call('record')
            ->assertHasErrors('quantity');

        $this->assertSame(0, $ingredient->movements()->count());
    }

    public function test_stock_is_the_sum_of_the_ledger_and_survives_editing_the_ingredient(): void
    {
        $ingredient = Ingredient::factory()->create(['unit' => IngredientUnit::Kilogram]);

        InventoryMovement::factory()->for($ingredient)->quantity('40.000')->create();
        InventoryMovement::factory()->for($ingredient)->adjustment('-2.500')->create();
        InventoryMovement::factory()->for($ingredient)->quantity('7.250')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.inventory.manage', ['ingredient' => $ingredient])
            ->set('name', 'Renamed Ingredient')
            ->set('reorder_level', '50')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $ingredient->fresh();

        $this->assertSame('Renamed Ingredient', $fresh->name);
        $this->assertSame(44750, $fresh->stockInThousandths());
        $this->assertTrue($fresh->isLowStock());
    }

    public function test_the_listing_aggregates_stock_and_counts_what_is_low(): void
    {
        $low = Ingredient::factory()->reorderAt('10.000')->create(['name' => 'Baking Powder']);
        InventoryMovement::factory()->for($low)->quantity('9.000')->create();

        $fine = Ingredient::factory()->reorderAt('10.000')->create(['name' => 'White Sugar']);
        InventoryMovement::factory()->for($fine)->quantity('80.000')->create();

        // No reorder level set, so an empty shelf is not reported as low.
        Ingredient::factory()->create(['name' => 'Vanilla Extract']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.inventory.index')
            ->assertSet('lowStockCount', 1)
            ->assertSee('Baking Powder')
            ->assertSee('White Sugar');

        $this->assertTrue($low->fresh()->isLowStock());
        $this->assertFalse($fine->fresh()->isLowStock());
    }

    public function test_ingredients_no_longer_stocked_are_hidden_unless_asked_for(): void
    {
        Ingredient::factory()->create(['name' => 'Cream of Tartar']);
        Ingredient::factory()->inactive()->create(['name' => 'Retired Premix']);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.index')
            ->assertSee('Cream of Tartar')
            ->assertDontSee('Retired Premix')
            ->set('includeInactive', true)
            ->assertSee('Retired Premix');
    }

    public function test_the_ledger_shows_every_movement_with_its_author(): void
    {
        $ingredient = Ingredient::factory()->create(['unit' => IngredientUnit::Kilogram]);
        $baker = User::factory()->baker()->create(['name' => 'Rosa Baker']);

        InventoryMovement::factory()->for($ingredient)->for($baker, 'recordedBy')->quantity('15.000')->create();
        InventoryMovement::factory()->for($ingredient)->for($baker, 'recordedBy')->adjustment('-1.500', 'Damaged sack')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $ingredient])
            ->assertSee('Rosa Baker')
            ->assertSee('Damaged sack')
            ->assertSee('Received')
            ->assertSee('Adjustment');
    }
}
