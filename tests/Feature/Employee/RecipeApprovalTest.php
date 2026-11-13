<?php

namespace Tests\Feature\Employee;

use App\Actions\BuildForecastContext;
use App\Actions\CountWorkNeedingAttention;
use App\Actions\RecordProductionRun;
use App\Enums\RecipeStatus;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * BR-016: a Baker's recipe waits for an Administrator, and only an approved
 * recipe can be baked from.
 */
class RecipeApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The recipe screen for a size, opened by the given employee.
     */
    private function manage(User $employee, ProductVariant $variant): Testable
    {
        return Livewire::actingAs($employee)
            ->test('pages::employee.production.recipes.manage', ['productVariant' => $variant]);
    }

    /**
     * One valid ingredient line, as the form sends it.
     *
     * @return array<int, array{id: null, ingredient_id: int, quantity: string, measure: string}>
     */
    private function oneLine(string $quantity = '2'): array
    {
        return [['id' => null, 'ingredient_id' => Ingredient::factory()->create()->id, 'quantity' => $quantity, 'measure' => 'unit']];
    }

    /**
     * A recipe in the given state with one line the stockroom can cover.
     */
    private function stockedRecipe(string $state = 'approved'): Recipe
    {
        $factory = Recipe::factory();

        $recipe = match ($state) {
            'pending' => $factory->pending()->create(),
            'rejected' => $factory->rejected()->create(),
            default => $factory->create(),
        };

        $ingredient = Ingredient::factory()->create();
        InventoryMovement::factory()->for($ingredient)->quantity('50.000')->create();
        RecipeItem::factory()->for($recipe)->quantity('1.000')->create(['ingredient_id' => $ingredient->id]);

        return $recipe;
    }

    public function test_a_bakers_new_recipe_waits_for_approval(): void
    {
        $baker = User::factory()->baker()->create();
        $variant = ProductVariant::factory()->create();

        $this->manage($baker, $variant)
            ->set('items', $this->oneLine())
            ->call('save')
            ->assertHasNoErrors();

        $recipe = $variant->fresh()->recipe;

        $this->assertSame(RecipeStatus::Pending, $recipe->status);
        $this->assertSame($baker->id, $recipe->submitted_by);
        $this->assertNotNull($recipe->submitted_at);
        $this->assertNull($recipe->reviewed_by);
        $this->assertNull($recipe->reviewed_at);
    }

    public function test_an_administrators_own_recipe_is_approved_on_save(): void
    {
        $administrator = User::factory()->administrator()->create();
        $variant = ProductVariant::factory()->create();

        $this->manage($administrator, $variant)
            ->set('items', $this->oneLine())
            ->call('save')
            ->assertHasNoErrors();

        $recipe = $variant->fresh()->recipe;

        $this->assertSame(RecipeStatus::Approved, $recipe->status);
        $this->assertSame($administrator->id, $recipe->submitted_by);
        $this->assertSame($administrator->id, $recipe->reviewed_by);
        $this->assertNotNull($recipe->reviewed_at);
    }

    public function test_a_bakers_edit_to_an_approved_recipe_sends_it_back_for_approval(): void
    {
        $recipe = Recipe::factory()->create([
            'reviewed_by' => User::factory()->administrator(),
            'reviewed_at' => now(),
        ]);

        $this->manage(User::factory()->baker()->create(), $recipe->productVariant)
            ->set('yield_quantity', '4')
            ->set('items', $this->oneLine())
            ->call('save')
            ->assertHasNoErrors();

        $recipe->refresh();

        $this->assertSame(4, $recipe->yield_quantity);
        $this->assertSame(RecipeStatus::Pending, $recipe->status);
        $this->assertNull($recipe->reviewed_by);
        $this->assertNull($recipe->reviewed_at);
    }

    public function test_an_administrator_approves_a_waiting_recipe(): void
    {
        $administrator = User::factory()->administrator()->create();
        $recipe = $this->stockedRecipe('pending');

        $this->manage($administrator, $recipe->productVariant)
            ->assertSee('Approve recipe')
            ->call('approve')
            ->assertHasNoErrors();

        $recipe->refresh();

        $this->assertSame(RecipeStatus::Approved, $recipe->status);
        $this->assertSame($administrator->id, $recipe->reviewed_by);
        $this->assertNotNull($recipe->reviewed_at);
        $this->assertNull($recipe->review_note);
    }

    public function test_an_administrator_sends_a_waiting_recipe_back_with_a_reason(): void
    {
        $administrator = User::factory()->administrator()->create();
        $recipe = $this->stockedRecipe('pending');

        $this->manage($administrator, $recipe->productVariant)
            ->set('review_note', '  Too much sugar for seven pans.  ')
            ->call('sendBack')
            ->assertHasNoErrors();

        $recipe->refresh();

        $this->assertSame(RecipeStatus::Rejected, $recipe->status);
        $this->assertSame($administrator->id, $recipe->reviewed_by);
        $this->assertSame('Too much sugar for seven pans.', $recipe->review_note);
    }

    public function test_sending_a_recipe_back_needs_a_reason(): void
    {
        $recipe = $this->stockedRecipe('pending');

        $this->manage(User::factory()->administrator()->create(), $recipe->productVariant)
            ->set('review_note', '   ')
            ->call('sendBack')
            ->assertHasErrors(['review_note' => 'required']);

        $this->assertSame(RecipeStatus::Pending, $recipe->fresh()->status);
    }

    public function test_a_baker_can_neither_approve_a_recipe_nor_send_one_back(): void
    {
        $recipe = $this->stockedRecipe('pending');

        $baker = User::factory()->baker()->create();

        $this->manage($baker, $recipe->productVariant)
            ->assertDontSee('Approve recipe')
            ->call('approve')
            ->assertForbidden();

        $this->manage($baker, $recipe->productVariant)
            ->set('review_note', 'No.')
            ->call('sendBack')
            ->assertForbidden();

        $recipe->refresh();

        $this->assertSame(RecipeStatus::Pending, $recipe->status);
        $this->assertNull($recipe->reviewed_by);
    }

    public function test_only_a_waiting_recipe_can_be_decided_on(): void
    {
        $approved = $this->stockedRecipe();
        $rejected = $this->stockedRecipe('rejected');
        $administrator = User::factory()->administrator()->create();

        $this->manage($administrator, $approved->productVariant)
            ->set('review_note', 'Changed my mind.')
            ->call('sendBack')
            ->assertHasErrors('review');

        $this->manage($administrator, $rejected->productVariant)
            ->call('approve')
            ->assertHasErrors('review');

        $this->assertSame(RecipeStatus::Approved, $approved->fresh()->status);
        $this->assertSame(RecipeStatus::Rejected, $rejected->fresh()->status);
    }

    public function test_a_recipe_saved_again_after_it_was_opened_is_not_approved_unseen(): void
    {
        $baker = User::factory()->baker()->create();
        $administrator = User::factory()->administrator()->create();
        $recipe = $this->stockedRecipe('pending');

        $review = $this->manage($administrator, $recipe->productVariant);

        $this->travel(1)->minutes();

        $this->manage($baker, $recipe->productVariant)
            ->set('yield_quantity', '9')
            ->call('save')
            ->assertHasNoErrors();

        // The Administrator's screen is still the one opened before the save.
        $this->actingAs($administrator);

        $review->call('approve')->assertHasErrors('review');

        $recipe->refresh();

        $this->assertSame(RecipeStatus::Pending, $recipe->status);
        $this->assertNull($recipe->reviewed_by);
    }

    public function test_correcting_a_recipe_that_was_sent_back_puts_it_up_for_approval_again(): void
    {
        $recipe = $this->stockedRecipe('rejected');

        $this->manage(User::factory()->baker()->create(), $recipe->productVariant)
            ->assertSee('Check the quantities.')
            ->set('items', $this->oneLine())
            ->call('save')
            ->assertHasNoErrors();

        $recipe->refresh();

        $this->assertSame(RecipeStatus::Pending, $recipe->status);
        $this->assertNull($recipe->review_note);
        $this->assertNull($recipe->reviewed_by);
    }

    public function test_only_an_approved_recipe_can_be_baked_from(): void
    {
        Outlet::factory()->mainBranch()->create();

        $baker = User::factory()->baker()->create();
        $approved = $this->stockedRecipe();
        $pending = $this->stockedRecipe('pending');
        $rejected = $this->stockedRecipe('rejected');

        $log = Livewire::actingAs($baker)->test('pages::employee.production.runs.index');

        $this->assertSame(
            [$approved->product_variant_id],
            $log->instance()->producibleVariants()->pluck('id')->all(),
        );

        // Left out of the picker is not enough: a crafted request is refused too.
        $log->set('product_variant_id', $pending->product_variant_id)
            ->set('quantity', '1')
            ->call('record')
            ->assertHasErrors('product_variant_id');

        foreach ([$pending, $rejected] as $recipe) {
            try {
                app(RecordProductionRun::class)->handle($recipe->productVariant, $baker, 1);
                $this->fail('A run was logged against a recipe nobody approved.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not been approved', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('production_runs', 0);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_a_recipe_becomes_bakeable_once_approved(): void
    {
        Outlet::factory()->mainBranch()->create();

        $recipe = $this->stockedRecipe('pending');
        $recipe->approve(User::factory()->administrator()->create());

        $run = app(RecordProductionRun::class)->handle($recipe->productVariant, User::factory()->baker()->create(), 2);

        $this->assertDatabaseHas('production_runs', ['id' => $run->id, 'recipe_id' => $recipe->id, 'quantity' => 2]);
    }

    public function test_the_forecast_counts_only_an_approved_recipe(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake']);
        $bakeable = ProductVariant::factory()->for($product)->create(['name' => 'Round']);
        $waiting = ProductVariant::factory()->for($product)->create(['name' => 'Slice']);

        Recipe::factory()->for($bakeable, 'productVariant')->create();
        Recipe::factory()->for($waiting, 'productVariant')->pending()->create();

        $products = collect(app(BuildForecastContext::class)->handle()['products'])->keyBy('size');

        $this->assertTrue($products['Round']['has_recipe']);
        $this->assertFalse($products['Slice']['has_recipe']);
    }

    public function test_each_role_is_told_about_the_recipes_waiting_on_it(): void
    {
        Recipe::factory()->pending()->count(2)->create();
        Recipe::factory()->rejected()->create();
        Recipe::factory()->create();

        $this->assertSame(['production' => 2], $this->attentionFor(User::factory()->administrator()->create()));
        $this->assertSame(['production' => 1], $this->attentionFor(User::factory()->baker()->create()));
        $this->assertSame([], $this->attentionFor(User::factory()->cashier()->create()));
    }

    public function test_the_listing_shows_where_each_recipe_stands_and_can_show_only_those_waiting(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake']);
        $waiting = ProductVariant::factory()->for($product)->create(['name' => 'Tincan Round']);
        $inUse = ProductVariant::factory()->for($product)->create(['name' => 'Large (10x14)']);

        Recipe::factory()->for($waiting, 'productVariant')->pending()->create();
        Recipe::factory()->for($inUse, 'productVariant')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.production.recipes.index')
            ->assertSet('pendingCount', 1)
            ->assertSee('Waiting for approval')
            ->assertSee('Review')
            ->set('onlyPending', true)
            ->assertSee('Tincan Round')
            ->assertDontSee('Large (10x14)');
    }

    public function test_recipes_written_before_approval_existed_stay_approved(): void
    {
        $variant = ProductVariant::factory()->create();

        DB::table('recipes')->insert([
            'product_variant_id' => $variant->id,
            'yield_quantity' => 1,
        ]);

        $this->assertTrue($variant->recipe->isApproved());
    }

    /**
     * What the sidebar would count for the given employee.
     *
     * @return array<string, int>
     */
    private function attentionFor(User $employee): array
    {
        return app(CountWorkNeedingAttention::class)->handle($employee);
    }
}
