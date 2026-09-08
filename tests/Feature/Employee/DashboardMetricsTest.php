<?php

namespace Tests\Feature\Employee;

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
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
}
