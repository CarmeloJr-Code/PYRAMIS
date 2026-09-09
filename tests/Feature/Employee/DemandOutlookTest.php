<?php

namespace Tests\Feature\Employee;

use App\Actions\BuildForecastContext;
use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemandOutlookTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so a window counted back from "today" is the same window on
        // every run. A Monday, so the weekday buckets are easy to reason about.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * The outlook as the action builds it.
     *
     * @return array<string, mixed>
     */
    private function outlook(int $lookbackDays = 28, int $horizonDays = 7): array
    {
        return app(BuildForecastContext::class)->handle($lookbackDays, $horizonDays);
    }

    /**
     * A completed sale of one line on the given day.
     */
    private function sell(string $day, int $quantity, ?ProductVariant $variant = null): void
    {
        $sale = Sale::factory()->for($this->main)->create(['sold_at' => $day.' 10:00:00']);

        $item = SaleItem::factory()->for($sale);

        if ($variant !== null) {
            $item = $item->for($variant, 'productVariant');
        }

        $item->create(['quantity' => $quantity, 'unit_price' => '100.00']);
    }

    /**
     * A size the bakery can sell.
     */
    private function variant(string $product, string $size): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size]);
    }

    public function test_only_the_administrator_reaches_the_outlook(): void
    {
        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.forecast'))
            ->assertOk();

        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.forecast'))
            ->assertForbidden();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.forecast'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.forecast'))->assertRedirect(route('login'));
    }

    public function test_the_daily_average_divides_by_every_day_in_the_window(): void
    {
        // Fourteen units across two days of a twenty-eight day window. A quiet
        // day is still a day, so the rate is 0.5 and not 7.
        $this->sell('2026-06-10', 8);
        $this->sell('2026-06-12', 6);

        $outlook = $this->outlook();

        $this->assertSame(28, $outlook['window']['days']);
        $this->assertSame('2026-05-19', $outlook['window']['from']);
        $this->assertSame('2026-06-15', $outlook['window']['to']);
        $this->assertSame(14, $outlook['demand']['units_sold']);
        $this->assertSame(0.5, $outlook['demand']['daily_average']);
    }

    public function test_the_horizon_starts_tomorrow_so_today_is_not_counted_twice(): void
    {
        $outlook = $this->outlook();

        $this->assertSame('2026-06-16', $outlook['horizon']['from']);
        $this->assertSame('2026-06-22', $outlook['horizon']['to']);
        $this->assertSame(7, $outlook['horizon']['days']);
    }

    public function test_the_trend_compares_the_recent_half_with_the_half_before_it(): void
    {
        // Previous half: 19 May – 1 June. Recent half: 2 – 15 June.
        $this->sell('2026-05-25', 10);
        $this->sell('2026-06-10', 20);

        $demand = $this->outlook()['demand'];

        $this->assertSame(20, $demand['recent_units']);
        $this->assertSame(10, $demand['previous_units']);
        $this->assertSame(100.0, $demand['change']);
        $this->assertSame(2.0, $demand['trend_factor']);
    }

    public function test_the_trend_factor_is_clamped_so_one_spike_cannot_run_away(): void
    {
        $this->sell('2026-05-25', 1);
        $this->sell('2026-06-10', 100);

        // A hundredfold week would otherwise project a rate the bakery has
        // never run at.
        $this->assertSame(2.0, $this->outlook()['demand']['trend_factor']);
    }

    public function test_the_trend_factor_is_clamped_the_other_way_too(): void
    {
        $this->sell('2026-05-25', 100);
        $this->sell('2026-06-10', 1);

        $this->assertSame(0.5, $this->outlook()['demand']['trend_factor']);
    }

    public function test_a_size_that_never_sold_still_appears_with_nothing_projected(): void
    {
        $this->variant('Ube Cake', 'Slice');

        $products = $this->outlook()['products'];

        $this->assertCount(1, $products);
        $this->assertSame('Slice', $products[0]['size']);
        $this->assertSame(0, $products[0]['units_sold']);
        $this->assertSame(0, $products[0]['projected_units']);
        $this->assertSame(0, $products[0]['shortfall']);
        // Nothing sold either side, so there is no comparison to draw.
        $this->assertNull($products[0]['change']);
        $this->assertFalse($products[0]['has_recipe']);
    }

    public function test_the_shortfall_is_projected_demand_less_what_is_already_made(): void
    {
        $whole = $this->variant('Ube Cake', 'Whole');

        // Fourteen units either side of the halfway line: a flat rate of one a
        // day, so seven days ahead projects seven.
        $this->sell('2026-05-25', 14, $whole);
        $this->sell('2026-06-10', 14, $whole);

        ProductStockMovement::factory()->for($whole)->for($this->main)->quantity(3)->create();

        $row = $this->outlook()['products'][0];

        $this->assertSame(28, $row['units_sold']);
        $this->assertSame(1.0, $row['daily_average']);
        $this->assertSame(1.0, $row['trend_factor']);
        $this->assertSame(7, $row['projected_units']);
        $this->assertSame(3, $row['stock_on_hand']);
        $this->assertSame(4, $row['shortfall']);
    }

    public function test_stock_is_counted_wherever_it_is_standing(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
        $whole = $this->variant('Ube Cake', 'Whole');

        ProductStockMovement::factory()->for($whole)->for($this->main)->quantity(5)->create();
        ProductStockMovement::factory()->for($whole)->for($kiosk)->quantity(4)->create();

        // Goods move between locations under BR-004, so what the business holds
        // is the sum of the shelves, not one of them.
        $this->assertSame(9, $this->outlook()['products'][0]['stock_on_hand']);
    }

    public function test_weekday_averages_divide_by_the_times_that_weekday_fell_in_the_window(): void
    {
        // Four Mondays fall inside a twenty-eight day window ending on one.
        $this->sell('2026-06-08', 8);

        $weekdays = collect($this->outlook()['weekdays'])->keyBy('weekday');

        $this->assertSame(4, $weekdays['Monday']['days_observed']);
        $this->assertSame(8, $weekdays['Monday']['units']);
        $this->assertSame(2.0, $weekdays['Monday']['average']);

        // A weekday nothing sold on averages nothing, rather than being absent.
        $this->assertSame(0.0, $weekdays['Thursday']['average']);
        $this->assertSame(4, $weekdays['Thursday']['days_observed']);
    }

    public function test_ingredient_cover_comes_from_what_the_ovens_drew_over_the_window(): void
    {
        $flour = Ingredient::factory()->create([
            'name' => 'Flour',
            'unit' => IngredientUnit::Kilogram,
        ]);

        InventoryMovement::factory()->for($flour)->quantity('38.000')
            ->create(['occurred_at' => '2026-05-20 08:00:00']);

        // Twenty-eight kilos drawn over twenty-eight days is one a day.
        InventoryMovement::factory()->for($flour)->usage('-28.000')
            ->create(['occurred_at' => '2026-06-01 08:00:00']);

        $row = collect($this->outlook()['ingredients'])->firstWhere('name', 'Flour');

        $this->assertSame(10.0, $row['stock']);
        $this->assertSame(1.0, $row['daily_usage']);
        $this->assertSame(7.0, $row['needed_for_horizon']);
        $this->assertSame(10.0, $row['days_of_cover']);
        $this->assertSame(0.0, $row['shortfall']);
        $this->assertFalse($row['needs_attention']);
    }

    public function test_an_ingredient_nothing_has_drawn_on_has_no_rate_to_run_out_at(): void
    {
        $sprinkles = Ingredient::factory()->create(['name' => 'Sprinkles']);

        InventoryMovement::factory()->for($sprinkles)->quantity('2.000')
            ->create(['occurred_at' => '2026-06-01 08:00:00']);

        $row = collect($this->outlook()['ingredients'])->firstWhere('name', 'Sprinkles');

        // Null, not zero: zero days of cover would read as an emergency.
        $this->assertNull($row['days_of_cover']);
        $this->assertFalse($row['needs_attention']);
    }

    public function test_an_ingredient_short_for_the_horizon_is_flagged(): void
    {
        $butter = Ingredient::factory()->reorderAt('0.000')->create(['name' => 'Butter']);

        InventoryMovement::factory()->for($butter)->quantity('30.000')
            ->create(['occurred_at' => '2026-05-20 08:00:00']);

        InventoryMovement::factory()->for($butter)->usage('-28.000')
            ->create(['occurred_at' => '2026-06-01 08:00:00']);

        $row = collect($this->outlook()['ingredients'])->firstWhere('name', 'Butter');

        // Two kilos left against seven days at one a day.
        $this->assertSame(2.0, $row['stock']);
        $this->assertSame(5.0, $row['shortfall']);
        $this->assertTrue($row['needs_attention']);
        $this->assertFalse($row['below_reorder']);
    }

    public function test_an_ingredient_below_its_reorder_level_is_flagged_whatever_the_usage(): void
    {
        $vanilla = Ingredient::factory()->reorderAt('5.000')->create(['name' => 'Vanilla']);

        InventoryMovement::factory()->for($vanilla)->quantity('1.000')
            ->create(['occurred_at' => '2026-06-01 08:00:00']);

        $row = collect($this->outlook()['ingredients'])->firstWhere('name', 'Vanilla');

        $this->assertTrue($row['below_reorder']);
        $this->assertTrue($row['needs_attention']);
    }

    public function test_a_crafted_lookback_or_horizon_falls_back_to_the_default(): void
    {
        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.forecast', ['lookback' => 9999, 'horizon' => -1]))
            ->assertOk();

        $outlook = $this->outlook(9999, -1);

        $this->assertSame(BuildForecastContext::LOOKBACK_DAYS, $outlook['window']['days']);
        $this->assertSame(BuildForecastContext::HORIZON_DAYS, $outlook['horizon']['days']);
    }

    public function test_the_outlook_only_reads_and_never_writes(): void
    {
        $whole = $this->variant('Ube Cake', 'Whole');

        $this->sell('2026-06-10', 20, $whole);
        Ingredient::factory()->create(['name' => 'Flour']);

        $before = [
            'product_stock_movements' => ProductStockMovement::count(),
            'inventory_movements' => InventoryMovement::count(),
            'production_runs' => ProductionRun::count(),
            'sales' => Sale::count(),
        ];

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.forecast')
            ->assertSee('Demand outlook');

        // BR-008 and BR-009: a recommendation reaches the business through a
        // person, never through this screen.
        $this->assertSame($before['product_stock_movements'], ProductStockMovement::count());
        $this->assertSame($before['inventory_movements'], InventoryMovement::count());
        $this->assertSame($before['production_runs'], ProductionRun::count());
        $this->assertSame($before['sales'], Sale::count());
    }

    public function test_the_screen_says_plainly_that_it_is_not_a_prediction(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.forecast')
            ->assertSee('An estimate, not a prediction')
            ->assertSeeText('Nothing here changes stock, production, orders or schedules');
    }
}
