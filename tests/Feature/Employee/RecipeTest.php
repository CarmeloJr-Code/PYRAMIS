<?php

namespace Tests\Feature\Employee;

use App\Enums\IngredientUnit;
use App\Enums\UserRole;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The recipe routes, which sit under the production capability.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'production' => ['employee.production'],
            'recipes' => ['employee.production.recipes.index'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_and_bakers_may_open_production(string $route): void
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

    public function test_a_cashier_cannot_open_a_recipe(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.production.recipes.manage', $variant))
            ->assertForbidden();
    }

    public function test_a_baker_writes_up_a_recipe(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Custard Cake']);
        $variant = ProductVariant::factory()->for($product)->create(['name' => 'Round']);

        $eggs = Ingredient::factory()->create(['name' => 'Extra Large Eggs', 'unit' => IngredientUnit::Piece]);
        $premix = Ingredient::factory()->create(['name' => 'Purple Yam Wet Premix', 'unit' => IngredientUnit::Gram]);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $variant])
            ->set('yield_quantity', 7)
            ->set('notes', 'Steam bake, one hour.')
            ->set('items', [
                ['id' => null, 'ingredient_id' => $eggs->id, 'quantity' => '21'],
                ['id' => null, 'ingredient_id' => $premix->id, 'quantity' => '450'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $recipe = $variant->fresh()->recipe;

        $this->assertNotNull($recipe);
        $this->assertSame(7, $recipe->yield_quantity);
        $this->assertSame('Steam bake, one hour.', $recipe->notes);
        $this->assertCount(2, $recipe->items);

        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $eggs->id,
            'quantity' => '21.000',
        ]);
    }

    public function test_editing_updates_kept_lines_and_drops_removed_ones(): void
    {
        $recipe = Recipe::factory()->yielding(4)->create();
        $kept = RecipeItem::factory()->for($recipe)->quantity('10.000')->create();
        $removed = RecipeItem::factory()->for($recipe)->quantity('2.000')->create();
        $added = Ingredient::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $recipe->productVariant])
            ->set('yield_quantity', 6)
            ->set('items', [
                ['id' => $kept->id, 'ingredient_id' => $kept->ingredient_id, 'quantity' => '12.5'],
                ['id' => null, 'ingredient_id' => $added->id, 'quantity' => '3'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6, $recipe->fresh()->yield_quantity);
        $this->assertDatabaseHas('recipe_items', ['id' => $kept->id, 'quantity' => '12.500']);
        $this->assertDatabaseMissing('recipe_items', ['id' => $removed->id]);
        $this->assertDatabaseHas('recipe_items', ['recipe_id' => $recipe->id, 'ingredient_id' => $added->id]);
        $this->assertSame(2, $recipe->fresh()->items()->count());
    }

    public function test_it_refuses_an_empty_yield_a_duplicate_ingredient_or_no_quantity(): void
    {
        $variant = ProductVariant::factory()->create();
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $variant])
            ->set('yield_quantity', 0)
            ->set('items', [
                ['id' => null, 'ingredient_id' => $ingredient->id, 'quantity' => '1'],
                ['id' => null, 'ingredient_id' => $ingredient->id, 'quantity' => ''],
            ])
            ->call('save')
            ->assertHasErrors(['yield_quantity', 'items.0.ingredient_id', 'items.1.quantity']);

        $this->assertNull($variant->fresh()->recipe);
    }

    public function test_an_ingredient_no_longer_stocked_cannot_be_called_for(): void
    {
        $variant = ProductVariant::factory()->create();
        $retired = Ingredient::factory()->inactive()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $variant])
            ->set('yield_quantity', 1)
            ->set('items', [['id' => null, 'ingredient_id' => $retired->id, 'quantity' => '1']])
            ->call('save')
            ->assertHasErrors('items');

        $this->assertNull($variant->fresh()->recipe);
    }

    public function test_a_recipe_line_belonging_to_another_recipe_is_never_adopted(): void
    {
        $mine = Recipe::factory()->create();
        $theirs = Recipe::factory()->create();
        $theirLine = RecipeItem::factory()->for($theirs)->quantity('5.000')->create();
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $mine->productVariant])
            ->set('yield_quantity', 1)
            ->set('items', [['id' => $theirLine->id, 'ingredient_id' => $ingredient->id, 'quantity' => '9']])
            ->call('save')
            ->assertHasNoErrors();

        // Their line is untouched, and mine got a new row of its own.
        $this->assertDatabaseHas('recipe_items', [
            'id' => $theirLine->id,
            'recipe_id' => $theirs->id,
            'quantity' => '5.000',
        ]);

        $this->assertSame(1, $mine->fresh()->items()->count());
        $this->assertSame('9.000', $mine->fresh()->items()->first()->quantity);
    }

    public function test_requirements_scale_from_the_batch(): void
    {
        $recipe = Recipe::factory()->yielding(7)->create();
        $eggs = Ingredient::factory()->create();
        $premix = Ingredient::factory()->create();

        RecipeItem::factory()->for($recipe)->quantity('21.000')->create(['ingredient_id' => $eggs->id]);
        RecipeItem::factory()->for($recipe)->quantity('450.000')->create(['ingredient_id' => $premix->id]);

        $recipe->load('items');

        // One batch is exactly what is written down.
        $this->assertSame(
            [$eggs->id => 21000, $premix->id => 450000],
            $recipe->requirementsInThousandths(7),
        );

        // Two batches double it, and a part batch scales rather than rounding up
        // to a whole one.
        $this->assertSame(
            [$eggs->id => 42000, $premix->id => 900000],
            $recipe->requirementsInThousandths(14),
        );

        $this->assertSame(
            [$eggs->id => 3000, $premix->id => 64286],
            $recipe->requirementsInThousandths(1),
        );
    }

    public function test_the_planner_flags_what_the_stockroom_cannot_cover(): void
    {
        $recipe = Recipe::factory()->yielding(2)->create();

        $short = Ingredient::factory()->create(['name' => 'Cream Cheese', 'unit' => IngredientUnit::Kilogram]);
        $plenty = Ingredient::factory()->create(['name' => 'White Sugar', 'unit' => IngredientUnit::Kilogram]);

        RecipeItem::factory()->for($recipe)->quantity('4.000')->create(['ingredient_id' => $short->id]);
        RecipeItem::factory()->for($recipe)->quantity('1.000')->create(['ingredient_id' => $plenty->id]);

        InventoryMovement::factory()->for($short)->quantity('5.000')->create();
        InventoryMovement::factory()->for($plenty)->quantity('90.000')->create();

        $component = Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $recipe->productVariant])
            ->set('plannedUnits', 4);

        $plan = $component->instance()->plan()->keyBy(fn (array $row): string => $row['ingredient']->name);

        // Four units is two batches: 8 kg of cream cheese against 5 in stock.
        $this->assertSame('8', $plan['Cream Cheese']['required']);
        $this->assertTrue($plan['Cream Cheese']['short']);

        $this->assertSame('2', $plan['White Sugar']['required']);
        $this->assertFalse($plan['White Sugar']['short']);
    }

    public function test_the_listing_separates_sizes_with_and_without_a_recipe(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake']);
        $withRecipe = ProductVariant::factory()->for($product)->create(['name' => 'Large (10x14)']);
        ProductVariant::factory()->for($product)->create(['name' => 'Tincan Round']);

        Recipe::factory()->for($withRecipe, 'productVariant')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.production.recipes.index')
            ->assertSet('missingCount', 1)
            ->assertSee('Large (10x14)')
            ->assertSee('Tincan Round')
            ->set('onlyMissing', true)
            ->assertSee('Tincan Round')
            ->assertDontSee('Large (10x14)');
    }
}
