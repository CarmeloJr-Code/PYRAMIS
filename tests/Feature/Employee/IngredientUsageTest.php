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
use Tests\TestCase;

class IngredientUsageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An ingredient holding the stock a bake will draw on.
     */
    private function stocked(string $name, string $quantity, IngredientUnit $unit = IngredientUnit::Kilogram): Ingredient
    {
        $ingredient = Ingredient::factory()->create(['name' => $name, 'unit' => $unit]);

        InventoryMovement::factory()->for($ingredient)->quantity($quantity)->create();

        return $ingredient;
    }

    public function test_only_administrators_and_bakers_may_open_the_usage_screen(): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.inventory.usage'));

            $role === UserRole::Cashier
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.inventory.usage'))->assertRedirect(route('login'));
    }

    public function test_a_baker_records_a_batch_of_usage(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '25.000');
        $sugar = $this->stocked('White Sugar', '10.000');
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.inventory.usage')
            ->set('lines', [
                ['ingredient_id' => $flour->id, 'quantity' => '3.5'],
                ['ingredient_id' => $sugar->id, 'quantity' => '1.25'],
            ])
            ->set('note', 'Ube custard cake, 7 pans')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $flour->id,
            'type' => InventoryMovementType::Usage->value,
            'quantity' => '-3.500',
            'recorded_by' => $baker->id,
            'note' => 'Ube custard cake, 7 pans',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $sugar->id,
            'quantity' => '-1.250',
        ]);

        $this->assertSame(21500, $flour->fresh()->stockInThousandths());
        $this->assertSame(8750, $sugar->fresh()->stockInThousandths());
    }

    public function test_a_batch_that_runs_short_on_one_line_records_nothing(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '25.000');
        $sugar = $this->stocked('White Sugar', '2.000');

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.usage')
            ->set('lines', [
                ['ingredient_id' => $flour->id, 'quantity' => '3'],
                ['ingredient_id' => $sugar->id, 'quantity' => '5'],
            ])
            ->call('record')
            ->assertHasErrors('lines');

        // The flour line came first and would have been written on its own; the
        // batch is one transaction, so neither survives.
        $this->assertSame(25000, $flour->fresh()->stockInThousandths());
        $this->assertSame(2000, $sugar->fresh()->stockInThousandths());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Usage)->count());
    }

    public function test_the_shortage_names_the_ingredient_that_ran_out(): void
    {
        $sugar = $this->stocked('White Sugar', '2.000');

        $component = Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.usage')
            ->set('lines', [['ingredient_id' => $sugar->id, 'quantity' => '5']])
            ->call('record')
            ->assertHasErrors('lines');

        $this->assertStringContainsString('White Sugar', $component->errors()->first('lines'));
    }

    public function test_an_ingredient_no_longer_stocked_cannot_be_used(): void
    {
        $retired = Ingredient::factory()->inactive()->create(['name' => 'Retired Premix']);
        InventoryMovement::factory()->for($retired)->quantity('10.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.usage')
            ->set('lines', [['ingredient_id' => $retired->id, 'quantity' => '1']])
            ->call('record')
            ->assertHasErrors('lines');

        $this->assertSame(10000, $retired->fresh()->stockInThousandths());
    }

    public function test_the_same_ingredient_cannot_be_listed_twice(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '25.000');

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.usage')
            ->set('lines', [
                ['ingredient_id' => $flour->id, 'quantity' => '1'],
                ['ingredient_id' => $flour->id, 'quantity' => '2'],
            ])
            ->call('record')
            ->assertHasErrors('lines.0.ingredient_id');

        $this->assertSame(25000, $flour->fresh()->stockInThousandths());
    }

    public function test_a_line_with_no_ingredient_or_no_quantity_is_refused(): void
    {
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.usage')
            ->set('lines', [['ingredient_id' => null, 'quantity' => '0']])
            ->call('record')
            ->assertHasErrors(['lines.0.ingredient_id', 'lines.0.quantity']);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_the_day_summary_totals_usage_and_ignores_other_days(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '80.000');

        InventoryMovement::factory()->for($flour)->usage('-3.000', 'Morning bake')->create([
            'occurred_at' => now()->setTime(7, 0),
        ]);

        InventoryMovement::factory()->for($flour)->usage('-2.500', 'Afternoon bake')->create([
            'occurred_at' => now()->setTime(14, 0),
        ]);

        InventoryMovement::factory()->for($flour)->usage('-9.000', 'Yesterday')->create([
            'occurred_at' => now()->subDay()->setTime(9, 0),
        ]);

        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.inventory.usage')
            ->assertSee('Morning bake')
            ->assertSee('Afternoon bake')
            ->assertDontSee('Yesterday');

        $totals = $component->instance()->totals();

        $this->assertCount(1, $totals);
        $this->assertSame('5.5', $totals->first()['used']);

        // The receipt is not usage, and neither is yesterday's bake.
        $this->assertSame(65500, $flour->fresh()->stockInThousandths());
    }

    public function test_usage_recorded_against_one_ingredient_can_never_add_stock(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '20.000');

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.inventory.show', ['ingredient' => $flour])
            ->set('type', InventoryMovementType::Usage->value)
            // A crafted request: the direction control is not drawn for usage.
            ->set('direction', 'in')
            ->set('quantity', '4')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(16000, $flour->fresh()->stockInThousandths());
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $flour->id,
            'type' => InventoryMovementType::Usage->value,
            'quantity' => '-4.000',
        ]);
    }
}
