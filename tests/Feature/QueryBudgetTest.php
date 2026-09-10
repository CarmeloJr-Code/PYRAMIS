<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What every listing, dashboard and report costs the database.
 *
 * Strict mode already turns a lazy load into an error everywhere but
 * production, which catches the N+1 that comes from a relation read inside a
 * loop. It does not catch the other kind: a query written deliberately, one per
 * row, inside a computed property. That one only shows as a page that gets
 * slower as the bakery gets busier — which is to say, in production and not
 * before.
 *
 * So the measure here is not a number, which would be brittle, but a shape: the
 * same screens are read twice, the second time with four times the rows behind
 * them, and a screen that costs more the second time is doing work per row.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The most queries any one screen may cost.
     *
     * Generous against today's worst (the dashboard and the forecast, in the
     * low teens) and there to catch a screen that answers a new question with
     * ten more round trips rather than a join.
     */
    private const CEILING = 25;

    public function test_no_screen_costs_more_queries_as_the_bakery_gets_busier(): void
    {
        $main = Outlet::factory()->mainBranch()->create();
        $kiosk = Outlet::factory()->create();
        $manager = User::factory()->administrator()->create();

        $variants = $this->catalogue();
        $ingredients = $this->stockroom();

        foreach ($variants as $variant) {
            ProductStockMovement::factory()->for($variant)->for($main)->quantity(20)->create();
        }

        $this->trade($variants, $main, $kiosk, sales: 20, orders: 15, expenses: 15, shifts: 8);

        $before = $this->measure($manager);

        // Four times the rows on every table those screens read.
        $this->trade($variants, $main, $kiosk, sales: 60, orders: 45, expenses: 45, shifts: 24);

        foreach ($ingredients as $ingredient) {
            InventoryMovement::factory()->count(5)->for($ingredient)->quantity('5')->create();
        }

        $after = $this->measure($manager);

        foreach ($before as $screen => $count) {
            $this->assertSame(
                $count,
                $after[$screen],
                "[{$screen}] costs more queries with more rows behind it, so it is doing work per row.",
            );

            $this->assertLessThanOrEqual(
                self::CEILING,
                $count,
                "[{$screen}] costs {$count} queries, over the ceiling of ".self::CEILING.'.',
            );
        }
    }

    /**
     * Every screen that draws a listing, a dashboard or a report.
     *
     * @return array<string, string>
     */
    private function screens(): array
    {
        return [
            'dashboard' => route('employee.dashboard'),
            'reports.sales' => route('employee.reports.sales'),
            'reports.inventory' => route('employee.reports.inventory'),
            'reports.production' => route('employee.reports.production'),
            'reports.expenses' => route('employee.reports.expenses'),
            'reports.workforce' => route('employee.reports.workforce'),
            'reports.outlets' => route('employee.reports.outlets'),
            'forecast' => route('employee.forecast'),
            'orders' => route('employee.orders.index'),
            'inventory' => route('employee.inventory.index'),
            'inventory.finished' => route('employee.inventory.finished'),
            'products' => route('employee.products.index'),
            'sales' => route('employee.sales.index'),
            'restocks' => route('employee.restocks.index'),
            'workforce' => route('employee.workforce'),
            'messages' => route('employee.messages.index'),
            'storefront.menu' => route('products.index'),
        ];
    }

    /**
     * What each screen costs, in queries.
     *
     * @return array<string, int>
     */
    private function measure(User $manager): array
    {
        $counts = [];

        foreach ($this->screens() as $screen => $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($manager)->get($url)->assertOk();

            $counts[$screen] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    /**
     * A catalogue of eight sizes across eight products.
     *
     * Named rather than left to the factory: its list of real sizes is finite,
     * and a test is not the place to find that out.
     *
     * @return Collection<int, ProductVariant>
     */
    private function catalogue(): Collection
    {
        $category = Category::factory()->create();

        return collect(range(1, 8))->map(fn (int $number): ProductVariant => ProductVariant::factory()
            ->for(Product::factory()->for($category)->create())
            ->create(['name' => 'Size '.$number]));
    }

    /**
     * Ten ingredients, each with stock behind it.
     *
     * @return EloquentCollection<int, Ingredient>
     */
    private function stockroom(): EloquentCollection
    {
        $ingredients = Ingredient::factory()->count(10)->create();

        foreach ($ingredients as $ingredient) {
            InventoryMovement::factory()->for($ingredient)->quantity('50')->create();
        }

        return $ingredients;
    }

    /**
     * A stretch of ordinary business across both locations.
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function trade(Collection $variants, Outlet $main, Outlet $kiosk, int $sales, int $orders, int $expenses, int $shifts): void
    {
        for ($i = 0; $i < $sales; $i++) {
            $sale = Sale::factory()->create(['outlet_id' => $i % 2 ? $main->id : $kiosk->id]);

            foreach ($variants->random(3) as $variant) {
                SaleItem::factory()->for($sale)->create(['product_variant_id' => $variant->id]);
            }
        }

        for ($i = 0; $i < $orders; $i++) {
            $order = Order::factory()->create(['outlet_id' => $kiosk->id]);
            OrderItem::factory()->for($order)->create(['product_variant_id' => $variants->random()->id]);
        }

        Expense::factory()->count($expenses)->create(['outlet_id' => $kiosk->id]);
        Shift::factory()->count($shifts)->create(['outlet_id' => $main->id]);
    }
}
