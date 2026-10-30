<?php

namespace Tests\Feature\Employee;

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * A completed sale of one line, on the given day.
     */
    private function sale(string $soldAt, int $quantity, string $unitPrice): Sale
    {
        $sale = Sale::factory()->for($this->outlet)->create(['sold_at' => $soldAt]);

        SaleItem::factory()->for($sale)->create([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $sale;
    }

    public function test_takings_are_summed_over_the_range_and_exclude_voided_sales(): void
    {
        $this->sale(now()->toDateString().' 09:00:00', 2, '500.00');
        $this->sale(now()->toDateString().' 14:00:00', 1, '70.50');

        // Yesterday's takings are not today's.
        $this->sale(now()->subDay()->toDateString().' 10:00:00', 3, '990.00');

        $voided = $this->sale(now()->toDateString().' 15:00:00', 4, '1000.00');
        $voided->void();

        $today = now()->toDateString();

        $this->assertSame(107050, Sale::takingsInCentavos($today, $today));
        $this->assertSame(
            404050,
            Sale::takingsInCentavos(now()->subDay()->toDateString(), $today),
        );
    }

    public function test_takings_can_be_narrowed_to_one_outlet(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $this->sale(now()->toDateString().' 09:00:00', 1, '500.00');

        $elsewhere = Sale::factory()->for($kiosk)->create(['sold_at' => now()]);
        SaleItem::factory()->for($elsewhere)->create(['quantity' => 1, 'unit_price' => '250.00']);

        $today = now()->toDateString();

        $this->assertSame(50000, Sale::takingsInCentavos($today, $today, $this->outlet->id));
        $this->assertSame(25000, Sale::takingsInCentavos($today, $today, $kiosk->id));
        $this->assertSame(75000, Sale::takingsInCentavos($today, $today));
    }

    public function test_spending_is_summed_over_the_range_inclusive_of_both_ends(): void
    {
        $category = ExpenseCategory::factory()->create();

        Expense::factory()->for($this->outlet)->for($category, 'category')
            ->amount('1200.00')->spentOn(now()->startOfMonth()->toDateString())->create();

        Expense::factory()->for($this->outlet)->for($category, 'category')
            ->amount('300.50')->spentOn(now()->toDateString())->create();

        Expense::factory()->for($this->outlet)->for($category, 'category')
            ->amount('9999.00')->spentOn(now()->subMonths(2)->toDateString())->create();

        $this->assertSame(
            150050,
            Expense::totalSpentInCentavos(now()->startOfMonth()->toDateString(), now()->toDateString()),
        );
    }

    public function test_production_units_are_summed_over_the_range(): void
    {
        $recipe = Recipe::factory()->create();

        ProductionRun::factory()->quantity(12)->create([
            'recipe_id' => $recipe->id,
            'product_variant_id' => $recipe->product_variant_id,
            'produced_at' => now()->setTime(6, 0),
        ]);

        ProductionRun::factory()->quantity(8)->create([
            'recipe_id' => $recipe->id,
            'product_variant_id' => $recipe->product_variant_id,
            'produced_at' => now()->setTime(13, 0),
        ]);

        ProductionRun::factory()->quantity(99)->create([
            'recipe_id' => $recipe->id,
            'product_variant_id' => $recipe->product_variant_id,
            'produced_at' => now()->subDay(),
        ]);

        $today = now()->toDateString();

        $this->assertSame(20, ProductionRun::unitsProduced($today, $today));
    }

    public function test_the_administrator_dashboard_shows_the_whole_business(): void
    {
        $product = Product::factory()->create(['name' => 'Ube Cake']);
        $variant = ProductVariant::factory()->for($product)->create();

        $this->sale(now()->toDateString().' 09:00:00', 2, '500.00');

        $order = Order::factory()->status(OrderStatus::Pending)->for($this->outlet)->create();
        OrderItem::factory()->for($order)->for($variant, 'productVariant')->create(['quantity' => 1, 'unit_price' => '500.00']);

        $category = ExpenseCategory::factory()->create();
        Expense::factory()->for($this->outlet)->for($category, 'category')->amount('250.00')->create();

        $low = Ingredient::factory()->reorderAt('10.000')->create();
        InventoryMovement::factory()->for($low)->quantity('4.000')->create();

        $restock = Restock::factory()->for($this->outlet)->create();
        RestockItem::factory()->for($restock)->requesting(3)->create(['product_variant_id' => $variant->id]);

        $shift = Shift::factory()->for($this->outlet)->create();
        ShiftAssignment::factory()->for($shift)->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.dashboard')
            ->assertSet('salesToday', '1,000.00')
            ->assertSet('openOrders', 1)
            ->assertSet('expensesThisMonth', '250.00')
            ->assertSet('lowStockCount', 1)
            ->assertSet('openRestocks', 1)
            ->assertSet('activeOutlets', 1)
            ->assertSet('onShiftToday', 1);
    }

    public function test_a_cashier_sees_the_counter_and_not_the_kitchen(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSeeText('Sales today')
            ->assertSeeText('Orders waiting')
            ->assertSeeText('Expenses this month')
            ->assertDontSeeText('Produced today')
            ->assertDontSeeText('Low on stock')
            ->assertDontSeeText('On shift today');
    }

    public function test_a_baker_sees_the_kitchen_and_not_the_till(): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSeeText('Produced today')
            ->assertSeeText('Low on stock')
            ->assertSeeText('Restocks open')
            ->assertDontSeeText('Sales today')
            ->assertDontSeeText('Expenses this month')
            ->assertDontSeeText('Outlets open');
    }

    public function test_everyone_sees_their_own_next_shift_and_unread_count(): void
    {
        $baker = User::factory()->baker()->create();

        $shift = Shift::factory()->for($this->outlet)->create([
            'name' => 'Morning bake',
            'starts_at' => now()->addDay()->setTime(5, 0),
            'ends_at' => now()->addDay()->setTime(13, 0),
        ]);
        ShiftAssignment::factory()->for($shift)->for($baker)->create();

        // Somebody else's shift is not the baker's next one.
        $other = Shift::factory()->for($this->outlet)->create([
            'name' => 'Counter cover',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(10),
        ]);
        ShiftAssignment::factory()->for($other)->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.dashboard')
            ->assertSee('Morning bake')
            ->assertDontSee('Counter cover')
            ->assertSet('unreadMessages', 0);
    }

    public function test_needs_attention_lists_only_the_roles_own_work_and_where_to_do_it(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->for($this->outlet)->create();
        OrderPayment::factory()->for($order)->create();
        Restock::factory()->for($this->outlet)->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.dashboard'))
            ->assertSeeText('1 payment to check')
            ->assertSee(route('employee.orders.index', ['status' => OrderStatus::Pending->value]))
            ->assertDontSeeText('restock to prepare');

        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertSeeText('1 restock to prepare')
            ->assertDontSeeText('payment to check');
    }

    public function test_needs_attention_says_so_when_nothing_is_waiting(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.dashboard'))
            ->assertSeeText('All caught up.');
    }

    public function test_the_last_full_week_is_set_against_the_one_before_and_today_is_left_out(): void
    {
        $this->sale(now()->toDateString().' 09:00:00', 1, '100.00');
        $this->sale(now()->subDays(2)->toDateString().' 09:00:00', 1, '1100.00');
        $this->sale(now()->subDays(9)->toDateString().' 09:00:00', 1, '1000.00');
        // Before the two weeks, so in neither.
        $this->sale(now()->subDays(20)->toDateString().' 09:00:00', 1, '5000.00');

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.dashboard')
            ->assertSet('salesToday', '100.00')
            ->assertSet('salesWeek', ['total' => 110000, 'change' => 10.0])
            ->assertSeeText('↑10%');
    }

    public function test_with_no_earlier_week_the_change_is_not_invented(): void
    {
        $this->sale(now()->subDay()->toDateString().' 09:00:00', 1, '500.00');

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.dashboard')
            ->assertSet('salesWeek', ['total' => 50000, 'change' => null])
            ->assertSeeText('No earlier week to compare');
    }

    public function test_output_is_read_day_by_day_the_same_way(): void
    {
        $variant = ProductVariant::factory()->create();

        ProductionRun::factory()->for($variant, 'productVariant')->create(['quantity' => 12, 'produced_at' => now()]);
        ProductionRun::factory()->for($variant, 'productVariant')->create(['quantity' => 30, 'produced_at' => now()->subDays(3)]);
        ProductionRun::factory()->for($variant, 'productVariant')->create(['quantity' => 20, 'produced_at' => now()->subDays(10)]);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.dashboard')
            ->assertSet('producedToday', 12)
            ->assertSet('outputWeek', ['total' => 30, 'change' => 50.0]);
    }

    public function test_pickups_today_lists_only_open_orders_due_today(): void
    {
        $due = Order::factory()->status(OrderStatus::Ready)->for($this->outlet)->create(['pickup_at' => now()->setTime(23, 0)]);
        $collected = Order::factory()->status(OrderStatus::Completed)->for($this->outlet)->create(['pickup_at' => now()->setTime(23, 0)]);
        $cancelled = Order::factory()->status(OrderStatus::Cancelled)->for($this->outlet)->create(['pickup_at' => now()->setTime(23, 0)]);
        $tomorrow = Order::factory()->status(OrderStatus::Pending)->for($this->outlet)->create(['pickup_at' => now()->addDay()->setTime(10, 0)]);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.dashboard')
            ->assertSet('pickupsTodayCount', 1)
            ->assertSee($due->reference)
            ->assertSee(route('employee.orders.show', $due))
            ->assertDontSee($collected->reference)
            ->assertDontSee($cancelled->reference)
            ->assertDontSee($tomorrow->reference);
    }

    public function test_the_kitchen_lists_name_what_to_prepare_and_what_is_low(): void
    {
        $requested = Restock::factory()->for($this->outlet)->create();
        $underway = Restock::factory()->for($this->outlet)->preparing()->create();

        $low = Ingredient::factory()->reorderAt('10.000')->create(['name' => 'Ube halaya base']);
        InventoryMovement::factory()->for($low)->quantity('4.000')->create();
        $fine = Ingredient::factory()->reorderAt('10.000')->create(['name' => 'Bread flour']);
        InventoryMovement::factory()->for($fine)->quantity('40.000')->create();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.dashboard')
            ->assertSee($requested->reference)
            ->assertDontSee($underway->reference)
            ->assertSee('Ube halaya base')
            ->assertSee(route('employee.inventory.show', $low))
            ->assertDontSee('Bread flour');
    }

    public function test_each_role_gets_only_its_own_lists(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.dashboard'))
            ->assertSee('data-test="pickups-today"', escape: false)
            ->assertDontSee('data-test="restocks-to-prepare"', escape: false)
            ->assertDontSee('data-test="low-ingredients"', escape: false)
            ->assertDontSeeText('Bake next');

        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertDontSee('data-test="pickups-today"', escape: false)
            ->assertSee('data-test="restocks-to-prepare"', escape: false)
            ->assertSee('data-test="low-ingredients"', escape: false)
            ->assertDontSeeText('Bake next');

        // The Administrator's comes in after the page, with a placeholder first.
        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.dashboard'))
            ->assertSeeText('Bake next');
    }

    public function test_bake_next_names_the_sizes_projected_to_run_short(): void
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'Ube Cake', 'is_active' => true]))
            ->create(['name' => 'Round (7x3)', 'is_available' => true]);

        // Selling steadily, with nothing on the shelf.
        foreach (range(1, 6) as $daysAgo) {
            $sale = Sale::factory()->for($this->outlet)->create(['sold_at' => now()->subDays($daysAgo)]);
            SaleItem::factory()->for($sale)->for($variant, 'productVariant')->create(['quantity' => 4]);
        }

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('bake-next')
            ->assertSee('Ube Cake')
            ->assertSee('Round (7x3)')
            ->assertSeeText('a suggestion, not a production order');
    }

    public function test_bake_next_says_so_when_nothing_is_short(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('bake-next')
            ->assertSeeText('Nothing short over the next 7 days.');
    }

    public function test_bake_next_is_the_administrators_alone(): void
    {
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('bake-next')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('bake-next')
            ->assertForbidden();
    }
}
