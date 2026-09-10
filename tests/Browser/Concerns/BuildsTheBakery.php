<?php

namespace Tests\Browser\Concerns;

use App\Enums\IngredientUnit;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The bakery a browser journey walks into.
 *
 * The four journeys all need the same small world — a main branch, an outlet,
 * one product worth ordering and the three employees who handle it — and a
 * browser test is slow enough already without each one inventing its own. The
 * records are built through the factories, never through the screens: what the
 * journey is there to prove starts once the browser opens.
 */
trait BuildsTheBakery
{
    private Outlet $main;

    private Outlet $kiosk;

    /**
     * Set the bakery up, and clear the caches a previous run left behind.
     */
    private function buildTheBakery(): void
    {
        // The storefront's order limiter counts against an address in a file
        // cache the server shares between runs, so a machine that has run these
        // journeys twenty times in an hour would otherwise start refusing them.
        Cache::flush();

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
    }

    /**
     * An employee who signs in with a password anyone reading this can guess,
     * which is the point: the journeys type it.
     */
    private function employee(string $role, string $name, string $email): User
    {
        return User::factory()->{$role}()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]);
    }

    /**
     * A cake on the menu, in one size, at a price the totals can be checked
     * against.
     */
    private function cake(string $product, string $size, string $price): ProductVariant
    {
        $category = Category::factory()->create(['name' => 'Cakes', 'slug' => 'cakes']);

        return ProductVariant::factory()
            ->for(Product::factory()->for($category)->create([
                'name' => $product,
                'slug' => str($product)->slug()->value(),
            ]))
            ->create(['name' => $size, 'price' => $price, 'is_available' => true]);
    }

    /**
     * A recipe for the given size, and enough of its one ingredient in the
     * stockroom to bake the run the journey logs.
     */
    private function recipeFor(ProductVariant $variant, int $yield, string $perBatch, string $inStock): Ingredient
    {
        $ingredient = Ingredient::factory()->create([
            'name' => 'Ube Halaya',
            'unit' => IngredientUnit::Kilogram,
        ]);

        InventoryMovement::factory()->for($ingredient)->quantity($inStock)->create();

        $recipe = Recipe::factory()->for($variant, 'productVariant')->yielding($yield)->create();

        RecipeItem::factory()->for($recipe)->quantity($perBatch)->create([
            'ingredient_id' => $ingredient->id,
        ]);

        return $ingredient;
    }
}
