<?php

namespace Tests\Feature\Employee;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
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

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * A completed sale of one line at the given outlet, on the given day.
     */
    private function sale(Outlet $outlet, string $soldAt, int $quantity, string $unitPrice, ?ProductVariant $variant = null): Sale
    {
        $sale = Sale::factory()->for($outlet)->create(['sold_at' => $soldAt]);

        $item = SaleItem::factory()->for($sale);

        if ($variant !== null) {
            $item = $item->for($variant, 'productVariant');
        }

        $item->create(['quantity' => $quantity, 'unit_price' => $unitPrice]);

        return $sale;
    }

    /**
     * Today, as the reports read it.
     */
    private function today(): string
    {
        return now()->toDateString();
    }

    public function test_every_role_reaches_the_reports_hub(): void
    {
        foreach (['administrator', 'cashier', 'baker'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('employee.reports.index'))
                ->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.reports.index'))->assertRedirect(route('login'));
        $this->get(route('employee.reports.sales'))->assertRedirect(route('login'));
    }

    public function test_a_cashier_gets_the_reports_their_work_touches_and_no_others(): void
    {
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.reports.index')
            ->assertSee('Sales')
            ->assertSee('Expenses')
            ->assertDontSee('Production')
            ->assertDontSee('Workforce')
            ->assertDontSee('Outlet performance');

        $this->actingAs($cashier)->get(route('employee.reports.sales'))->assertOk();
        $this->actingAs($cashier)->get(route('employee.reports.expenses'))->assertOk();

        $this->actingAs($cashier)->get(route('employee.reports.inventory'))->assertForbidden();
        $this->actingAs($cashier)->get(route('employee.reports.production'))->assertForbidden();
        $this->actingAs($cashier)->get(route('employee.reports.workforce'))->assertForbidden();
        $this->actingAs($cashier)->get(route('employee.reports.outlets'))->assertForbidden();
    }

    public function test_a_baker_gets_the_kitchen_reports_and_no_others(): void
    {
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.reports.index')
            ->assertSee('Inventory')
            ->assertSee('Production')
            ->assertDontSee('Sales')
            ->assertDontSee('Expenses')
            ->assertDontSee('Outlet performance');

        $this->actingAs($baker)->get(route('employee.reports.inventory'))->assertOk();
        $this->actingAs($baker)->get(route('employee.reports.production'))->assertOk();

        $this->actingAs($baker)->get(route('employee.reports.sales'))->assertForbidden();
        $this->actingAs($baker)->get(route('employee.reports.expenses'))->assertForbidden();
        $this->actingAs($baker)->get(route('employee.reports.workforce'))->assertForbidden();
        $this->actingAs($baker)->get(route('employee.reports.outlets'))->assertForbidden();
    }

    public function test_the_administrator_reaches_every_report(): void
    {
        $administrator = User::factory()->administrator()->create();

        foreach (['sales', 'inventory', 'production', 'expenses', 'workforce', 'outlets'] as $report) {
            $this->actingAs($administrator)
                ->get(route('employee.reports.'.$report))
                ->assertOk();
        }
    }

    public function test_daily_takings_group_by_day_and_leave_out_voided_sales(): void
    {
        $this->sale($this->main, $this->today().' 09:00:00', 2, '500.00');
        $this->sale($this->main, $this->today().' 14:00:00', 1, '70.50');
        $this->sale($this->main, now()->subDay()->toDateString().' 10:00:00', 3, '100.00');

        $this->sale($this->main, $this->today().' 16:00:00', 4, '1000.00')->void();

        $daily = Sale::dailyTakings(now()->subDay()->toDateString(), $this->today());

        $this->assertCount(2, $daily);

        $this->assertSame(now()->subDay()->toDateString(), $daily->first()['day']);
        $this->assertSame(30000, $daily->first()['takings']);
        $this->assertSame(3, $daily->first()['units']);
        $this->assertSame(1, $daily->first()['sales']);

        // Two lines on two sales today, and the voided one counts for nothing.
        $this->assertSame($this->today(), $daily->last()['day']);
        $this->assertSame(107050, $daily->last()['takings']);
        $this->assertSame(3, $daily->last()['units']);
        $this->assertSame(2, $daily->last()['sales']);
    }

    public function test_takings_by_product_name_the_size_that_sold(): void
    {
        $cake = Product::factory()->create(['name' => 'Ube Cake']);
        $whole = ProductVariant::factory()->for($cake)->create(['name' => 'Whole']);
        $slice = ProductVariant::factory()->for($cake)->create(['name' => 'Slice']);

        $this->sale($this->main, $this->today().' 09:00:00', 2, '500.00', $whole);
        $this->sale($this->main, $this->today().' 10:00:00', 3, '80.00', $slice);
        $this->sale($this->main, $this->today().' 11:00:00', 1, '500.00', $whole);

        $rows = Sale::takingsByProduct($this->today(), $this->today());

        $this->assertCount(2, $rows);

        // Ordered by takings, so the whole cakes lead.
        $this->assertSame(['Ube Cake', 'Whole', 3, 150000], [
            $rows->first()['product'],
            $rows->first()['size'],
            $rows->first()['units'],
            $rows->first()['takings'],
        ]);

        $this->assertSame(['Slice', 3, 24000], [
            $rows->last()['size'],
            $rows->last()['units'],
            $rows->last()['takings'],
        ]);
    }

    public function test_takings_by_outlet_keep_the_locations_apart(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $this->sale($this->main, $this->today().' 09:00:00', 1, '500.00');
        $this->sale($kiosk, $this->today().' 10:00:00', 2, '250.00');

        $rows = Sale::takingsByOutlet($this->today(), $this->today())->keyBy('name');

        $this->assertSame(50000, $rows['Main Branch']['takings']);
        $this->assertSame(50000, $rows['Mall Kiosk']['takings']);
        $this->assertSame(2, $rows['Mall Kiosk']['units']);
    }

    public function test_the_sales_report_narrows_to_one_outlet(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $this->sale($this->main, $this->today().' 09:00:00', 1, '500.00');
        $this->sale($kiosk, $this->today().' 10:00:00', 2, '250.00');

        $report = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.reports.sales')
            ->set('outletId', $kiosk->id);

        $this->assertSame(50000, $report->instance()->takings());
        $this->assertSame(1, $report->instance()->salesCount());

        // The by-outlet table is the comparison, so it keeps both.
        $this->assertCount(2, $report->instance()->byOutlet());
    }

    public function test_stock_movement_separates_what_came_in_from_what_was_used(): void
    {
        $flour = Ingredient::factory()->create(['name' => 'Flour']);

        InventoryMovement::factory()->for($flour)->quantity('25.000')->create(['occurred_at' => now()]);
        InventoryMovement::factory()->for($flour)->usage('-4.500')->create(['occurred_at' => now()]);
        InventoryMovement::factory()->for($flour)->adjustment('-0.500')->create(['occurred_at' => now()]);

        // Outside the range, so none of it counts.
        InventoryMovement::factory()->for($flour)->quantity('99.000')
            ->create(['occurred_at' => now()->subMonths(2)]);

        $rows = InventoryMovement::summaryByIngredient($this->today(), $this->today());

        $this->assertCount(1, $rows);
        $this->assertSame('Flour', $rows->first()['name']);
        $this->assertSame(25000, $rows->first()['received']);
        $this->assertSame(-4500, $rows->first()['used']);
        $this->assertSame(-500, $rows->first()['adjusted']);
        $this->assertSame(3, $rows->first()['entries']);
    }

    public function test_production_output_is_grouped_by_day_and_by_size(): void
    {
        $recipe = Recipe::factory()->create();
        $variant = ProductVariant::query()->findOrFail($recipe->product_variant_id);
        $variant->update(['name' => 'Whole']);

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

        ProductionRun::factory()->quantity(5)->create([
            'recipe_id' => $recipe->id,
            'product_variant_id' => $recipe->product_variant_id,
            'produced_at' => now()->subDay()->setTime(6, 0),
        ]);

        $daily = ProductionRun::dailyOutput(now()->subDay()->toDateString(), $this->today());

        $this->assertCount(2, $daily);
        $this->assertSame(5, $daily->first()['units']);
        $this->assertSame(1, $daily->first()['runs']);
        $this->assertSame(20, $daily->last()['units']);
        $this->assertSame(2, $daily->last()['runs']);

        $bySize = ProductionRun::outputByVariant(now()->subDay()->toDateString(), $this->today());

        $this->assertCount(1, $bySize);
        $this->assertSame('Whole', $bySize->first()['size']);
        $this->assertSame(25, $bySize->first()['units']);
        $this->assertSame(3, $bySize->first()['runs']);
    }

    public function test_spending_is_grouped_by_category_and_by_location(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
        $rent = ExpenseCategory::factory()->create(['name' => 'Rent']);
        $power = ExpenseCategory::factory()->create(['name' => 'Utilities']);

        Expense::factory()->for($this->main)->for($rent, 'category')->amount('5000.00')->create();
        Expense::factory()->for($kiosk)->for($rent, 'category')->amount('2000.00')->create();
        Expense::factory()->for($kiosk)->for($power, 'category')->amount('750.50')->create();

        Expense::factory()->for($kiosk)->for($power, 'category')->amount('9999.00')
            ->spentOn(now()->subMonths(2)->toDateString())->create();

        $byCategory = Expense::totalsByCategory(now()->startOfMonth()->toDateString(), $this->today());

        $this->assertSame('Rent', $byCategory->first()['name']);
        $this->assertSame(700000, $byCategory->first()['total']);
        $this->assertSame(2, $byCategory->first()['entries']);
        $this->assertSame(75050, $byCategory->last()['total']);

        $byOutlet = Expense::totalsByOutlet(now()->startOfMonth()->toDateString(), $this->today())->keyBy('name');

        $this->assertSame(500000, $byOutlet['Main Branch']['total']);
        $this->assertSame(275050, $byOutlet['Mall Kiosk']['total']);

        // Narrowing to one location narrows the headings with it.
        $narrowed = Expense::totalsByCategory(now()->startOfMonth()->toDateString(), $this->today(), $kiosk->id);

        $this->assertSame(200000, $narrowed->first()['total']);
    }

    public function test_only_delivered_restocks_count_towards_what_an_outlet_received(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $delivered = Restock::factory()->for($kiosk)->delivered()->create();
        RestockItem::factory()->for($delivered)->requesting(6)->prepared(5)->create();

        // Asked for but never sent, so nothing arrived.
        $pending = Restock::factory()->for($kiosk)->create();
        RestockItem::factory()->for($pending)->requesting(20)->create();

        $rows = Restock::deliveriesByOutlet($this->today(), $this->today())->keyBy('name');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows['Mall Kiosk']['restocks']);
        // What was set aside, not what was asked for.
        $this->assertSame(5, $rows['Mall Kiosk']['units']);
    }

    public function test_outlet_performance_puts_takings_restocks_and_spending_on_one_row(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $this->sale($kiosk, $this->today().' 10:00:00', 2, '250.00');

        $restock = Restock::factory()->for($kiosk)->delivered()->create();
        RestockItem::factory()->for($restock)->requesting(5)->prepared(5)->create();

        Expense::factory()->for($kiosk)->for(ExpenseCategory::factory(), 'category')->amount('100.00')->create();

        $rows = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.reports.outlets')
            ->instance()
            ->rows()
            ->keyBy(fn (array $row): string => $row['outlet']->name);

        $this->assertSame(50000, $rows['Mall Kiosk']['takings']);
        $this->assertSame(1, $rows['Mall Kiosk']['restocks']);
        $this->assertSame(5, $rows['Mall Kiosk']['received']);
        $this->assertSame(10000, $rows['Mall Kiosk']['spent']);
        $this->assertSame(40000, $rows['Mall Kiosk']['net']);

        // The main branch is the source, so it receives nothing and still
        // appears with zeroes rather than being left out.
        $this->assertSame(0, $rows['Main Branch']['received']);
        $this->assertSame(0, $rows['Main Branch']['takings']);
    }

    public function test_the_workforce_report_counts_rostered_hours_and_what_each_employee_recorded(): void
    {
        $cashier = User::factory()->cashier()->create(['name' => 'Rosa Cashier']);

        $shift = Shift::factory()->for($this->main)->on($this->today(), '09:00', '17:00')->create();
        ShiftAssignment::factory()->for($shift)->for($cashier)->create();

        Sale::factory()->for($this->main)->create([
            'sold_at' => now(),
            'recorded_by' => $cashier->id,
        ]);

        Expense::factory()->for($this->main)->for(ExpenseCategory::factory(), 'category')
            ->create(['recorded_by' => $cashier->id]);

        $employees = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.reports.workforce')
            ->instance()
            ->employees()
            ->keyBy('name');

        $this->assertSame(1, $employees['Rosa Cashier']['shifts']);
        $this->assertSame(480, $employees['Rosa Cashier']['minutes']);
        $this->assertSame(1, $employees['Rosa Cashier']['sales']);
        $this->assertSame(1, $employees['Rosa Cashier']['expenses']);
        $this->assertSame(0, $employees['Rosa Cashier']['runs']);
    }

    public function test_a_closed_account_stays_out_unless_it_did_something_in_the_range(): void
    {
        $gone = User::factory()->cashier()->create(['name' => 'Departed Cashier', 'is_active' => false]);

        $administrator = User::factory()->administrator()->create();

        $employees = Livewire::actingAs($administrator)
            ->test('pages::employee.reports.workforce')
            ->instance()
            ->employees()
            ->pluck('name');

        $this->assertFalse($employees->contains('Departed Cashier'));

        Sale::factory()->for($this->main)->create([
            'sold_at' => now(),
            'recorded_by' => $gone->id,
        ]);

        $employees = Livewire::actingAs($administrator)
            ->test('pages::employee.reports.workforce')
            ->instance()
            ->employees()
            ->pluck('name');

        $this->assertTrue($employees->contains('Departed Cashier'));
    }

    public function test_a_range_entered_back_to_front_is_read_in_order(): void
    {
        $report = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.reports.sales')
            ->set('from', '2026-09-30')
            ->set('to', '2026-09-01');

        $this->assertSame('2026-09-01', $report->instance()->range()->from());
        $this->assertSame('2026-09-30', $report->instance()->range()->to());
    }

    public function test_a_crafted_date_in_the_query_string_falls_back_instead_of_breaking(): void
    {
        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.reports.sales', ['from' => 'not-a-date', 'to' => '"; drop table sales; --']))
            ->assertOk();
    }

    public function test_the_comparison_period_is_the_same_length_immediately_before(): void
    {
        $report = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.reports.sales')
            ->set('from', '2026-09-01')
            ->set('to', '2026-09-07');

        $this->assertSame(7, $report->instance()->range()->days());
        $this->assertSame('2026-08-25', $report->instance()->range()->previousFrom());
        $this->assertSame('2026-08-31', $report->instance()->range()->previousTo());
    }
}
