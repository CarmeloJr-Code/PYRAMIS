<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seed that runs on every deploy.
 *
 * It runs unattended against the bakery's live database, which makes the second
 * run the one worth testing: a seeder that is only correct against an empty
 * database is a seeder that quietly undoes the business's work every time the
 * application ships.
 */
class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_an_empty_database_with_the_reference_data(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertTrue(Category::query()->exists());
        $this->assertTrue(Product::query()->exists());
        $this->assertTrue(ProductVariant::query()->exists());
        $this->assertTrue(Ingredient::query()->exists());
        $this->assertTrue(ExpenseCategory::query()->exists());

        // One outlet, and it is the branch that runs production (BR-003).
        $this->assertSame(1, Outlet::query()->count());
        $this->assertTrue(Outlet::query()->sole()->is_main_branch);
    }

    public function test_it_creates_no_accounts(): void
    {
        // Production accounts are minted one at a time by make:employee, so
        // that the password is printed once and never committed.
        $this->seed(ProductionSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(ProductionSeeder::class);

        $before = $this->census();

        $this->seed(ProductionSeeder::class);

        $this->assertSame($before, $this->census());
    }

    public function test_it_does_not_put_back_what_the_business_changed(): void
    {
        $this->seed(ProductionSeeder::class);

        // The Administrator does what the placeholders invite: renames the
        // branch, retires a heading, stops carrying an ingredient.
        $outlet = Outlet::query()->sole();
        $outlet->update(['name' => 'Purple Yam Malaybalay', 'address' => 'Sayre Highway, Malaybalay']);

        $heading = ExpenseCategory::query()->firstOrFail();
        $heading->update(['name' => 'Deliveries and fuel']);

        $ingredient = Ingredient::query()->firstOrFail();
        $ingredient->delete();

        $census = $this->census();

        $this->seed(ProductionSeeder::class);

        $this->assertSame($census, $this->census());
        $this->assertSame('Purple Yam Malaybalay', $outlet->fresh()->name);
        $this->assertSame('Deliveries and fuel', $heading->fresh()->name);
        $this->assertNull($ingredient->fresh());
    }

    public function test_a_curated_catalogue_survives_the_next_deploy(): void
    {
        $this->seed(ProductionSeeder::class);

        $variant = ProductVariant::query()->firstOrFail();
        $variant->update(['price' => '1495.00', 'is_available' => false]);

        $this->seed(ProductionSeeder::class);

        // A price rise and a size taken off sale are the business's decisions,
        // not the menu board's.
        $variant->refresh();

        $this->assertSame('1495.00', $variant->price);
        $this->assertFalse($variant->is_available);
    }

    /**
     * How many of each kind of reference record there are.
     *
     * @return array<string, int>
     */
    private function census(): array
    {
        return [
            'categories' => Category::query()->count(),
            'products' => Product::query()->count(),
            'variants' => ProductVariant::query()->count(),
            'ingredients' => Ingredient::query()->count(),
            'expense categories' => ExpenseCategory::query()->count(),
            'outlets' => Outlet::query()->count(),
        ];
    }
}
