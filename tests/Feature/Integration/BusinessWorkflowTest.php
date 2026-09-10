<?php

namespace Tests\Feature\Integration;

use App\Enums\IngredientUnit;
use App\Enums\OrderStatus;
use App\Enums\ProductStockMovementType;
use App\Enums\RestockStatus;
use App\Enums\SaleStatus;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 13: the modules, walked end to end through the screens that drive them.
 *
 * Every slice has its own tests, and each one passes in isolation. What is left
 * to prove is that the boundaries between them agree — that a bake the Baker
 * logs is the stock the Administrator can send, that the goods a cashier rings
 * up come off the shelf they were delivered to, and that the figure a report
 * shows is the one the forecast reads. Those are the disagreements a per-module
 * test cannot see, so these tests never shortcut through an action or a factory
 * where a real screen exists to press.
 */
class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;

    private Outlet $kiosk;

    private User $manager;

    private User $baker;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Finished goods come out of the oven at the main branch and travel to
        // the outlets, which never produce their own (BR-003, BR-004).
        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);

        $this->manager = User::factory()->administrator()->create();
        $this->baker = User::factory()->baker()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    /**
     * An ingredient holding stock a bake can draw on.
     */
    private function stocked(string $name, string $quantity, IngredientUnit $unit = IngredientUnit::Kilogram): Ingredient
    {
        $ingredient = Ingredient::factory()->create(['name' => $name, 'unit' => $unit]);

        InventoryMovement::factory()->for($ingredient)->quantity($quantity)->create();

        return $ingredient;
    }

    /**
     * A sellable size with a recipe: one batch of $yield units, calling for the
     * given per-batch quantities keyed by ingredient id.
     *
     * @param  array<int, string>  $items
     */
    private function producible(string $product, string $size, string $price, int $yield, array $items): ProductVariant
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size, 'price' => $price, 'is_available' => true]);

        $recipe = Recipe::factory()->for($variant, 'productVariant')->yielding($yield)->create();

        foreach ($items as $ingredientId => $quantity) {
            RecipeItem::factory()->for($recipe)->quantity($quantity)->create(['ingredient_id' => $ingredientId]);
        }

        return $variant->fresh();
    }

    /**
     * A pre-order placed the way a customer places one: no account, a pickup
     * outlet, and a cart held in the session (BR-001).
     */
    private function placeOrder(ProductVariant $variant, int $quantity, ?Outlet $outlet = null): Order
    {
        Session::put('cart', [$variant->id => $quantity]);

        Livewire::test('pages::storefront.order')
            ->set('customer_name', 'Ana Reyes')
            ->set('customer_phone', '09171234567')
            ->set('outlet_id', ($outlet ?? $this->kiosk)->id)
            ->set('pickup_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('submit')
            ->assertHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * Walk an order forward through the cashier's screen.
     *
     * @param  list<OrderStatus>  $expected
     */
    private function advance(Order $order, array $expected): void
    {
        foreach ($expected as $status) {
            Livewire::actingAs($this->cashier)
                ->test('pages::employee.orders.show', ['order' => $order->fresh()])
                ->call('advance');

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    /**
     * Put finished goods on a shelf the way production does, for the tests
     * whose subject is further down the chain.
     */
    private function shelve(ProductVariant $variant, Outlet $outlet, int $units): void
    {
        ProductStockMovement::factory()
            ->for($variant, 'productVariant')
            ->for($outlet)
            ->quantity($units)
            ->create();
    }

    /**
     * What one location's ledger says it is holding of a size.
     */
    private function ledgerBalance(ProductVariant $variant, Outlet $outlet): int
    {
        return (int) ProductStockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->where('outlet_id', $outlet->id)
            ->sum('quantity');
    }

    /**
     * The outlet-performance rows, keyed by outlet name.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function outletRows(): Collection
    {
        return Livewire::actingAs($this->manager)
            ->test('pages::employee.reports.outlets')
            ->instance()
            ->rows()
            ->keyBy(fn (array $row): string => $row['outlet']->name);
    }

    public function test_the_chain_runs_from_a_customer_order_through_to_the_forecast(): void
    {
        $flour = $this->stocked('All-Purpose Flour', '50.000');
        $ube = $this->stocked('Ube Halaya', '20.000');
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $flour->id => '1.500',
            $ube->id => '0.500',
        ]);

        // 1. A customer pre-orders four cakes for pickup at the kiosk.
        $order = $this->placeOrder($variant, 4);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame($this->kiosk->id, $order->outlet_id);
        $this->assertSame('2000.00', $order->load('items')->total());

        // 2. The cashier takes it as far as ready for pickup. A pre-order is a
        // promise, not a transaction, so no stock and no money have moved yet.
        $this->advance($order, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready]);

        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertDatabaseCount('sales', 0);

        // 3. The Baker bakes ten. The stockroom loses what the recipe called
        // for, and the finished cakes land on the main branch's shelf.
        Livewire::actingAs($this->baker)
            ->test('pages::employee.production.runs.index')
            ->set('product_variant_id', $variant->id)
            ->set('quantity', 10)
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(35000, $flour->fresh()->stockInThousandths());
        $this->assertSame(15000, $ube->fresh()->stockInThousandths());
        $this->assertSame(10, $variant->stockAt($this->main));
        $this->assertSame(0, $variant->stockAt($this->kiosk));

        // 4. The Administrator schedules six of them out to the kiosk. Nothing
        // travels on scheduling — the cakes are still on the main branch's
        // shelf until someone sends them.
        Livewire::actingAs($this->manager)
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 6]])
            ->call('save')
            ->assertHasNoErrors();

        $restock = Restock::query()->firstOrFail();

        $this->assertSame(RestockStatus::Requested, $restock->status);
        $this->assertSame(10, $variant->stockAt($this->main));

        // 5. The Baker sets them aside and sends them out. Both halves of the
        // transfer are written, so the six are never in two places at once.
        Livewire::actingAs($this->baker)
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->call('startPreparing')
            ->call('savePrepared')
            ->call('deliver');

        $this->assertSame(RestockStatus::Delivered, $restock->fresh()->status);
        $this->assertSame(4, $variant->stockAt($this->main));
        $this->assertSame(6, $variant->stockAt($this->kiosk));

        // 6. The customer collects and pays. Completing the order raises the
        // sale, and the cakes leave the shelf they were delivered to.
        $this->advance($order, [OrderStatus::Completed]);

        $sale = Sale::query()->firstOrFail();

        $this->assertSame($order->id, $sale->order_id);
        $this->assertSame($this->kiosk->id, $sale->outlet_id);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame($this->cashier->id, $sale->recorded_by);
        $this->assertSame('2000.00', $sale->load('items')->total());

        $this->assertSame(2, $variant->stockAt($this->kiosk));
        $this->assertSame(4, $variant->stockAt($this->main));

        $this->assertDatabaseHas('product_stock_movements', [
            'sale_id' => $sale->id,
            'outlet_id' => $this->kiosk->id,
            'type' => ProductStockMovementType::Sale->value,
            'quantity' => -4,
        ]);

        // 7. The sales report reads the same transaction.
        $report = Livewire::actingAs($this->manager)->test('pages::employee.reports.sales');

        $this->assertSame(200000, $report->instance()->takings());
        $this->assertSame(1, $report->instance()->salesCount());
        $this->assertSame(4, $report->instance()->units());

        $byOutlet = $report->instance()->byOutlet()->keyBy('name');

        $this->assertSame(200000, $byOutlet['Mall Kiosk']['takings']);
        $this->assertArrayNotHasKey('Main Branch', $byOutlet->all());

        // 8. Outlet performance reads across sales and restocking at once, and
        // counts what was set aside rather than what was asked for.
        $rows = $this->outletRows();

        $this->assertSame(6, $rows['Mall Kiosk']['received']);
        $this->assertSame(1, $rows['Mall Kiosk']['restocks']);
        $this->assertSame(200000, $rows['Mall Kiosk']['takings']);
        $this->assertSame(0, $rows['Main Branch']['takings']);

        // 9. The dashboard's headline figures move with it.
        $dashboard = Livewire::actingAs($this->manager)->test('pages::employee.dashboard');

        $this->assertSame('2,000.00', $dashboard->instance()->salesToday());
        $this->assertSame(10, $dashboard->instance()->producedToday());
        $this->assertSame(0, $dashboard->instance()->openOrders());

        // 10. And the deterministic outlook the AI reading is written from sees
        // the day's takings, the bake, and what is left on both shelves.
        $outlook = Livewire::actingAs($this->manager)
            ->test('pages::employee.forecast')
            ->instance()
            ->outlook();

        $this->assertSame(4, $outlook['demand']['units_sold']);
        $this->assertSame(1, $outlook['demand']['sales']);
        $this->assertSame(200000, $outlook['demand']['takings']);
        $this->assertSame(10, $outlook['production']['units_produced']);
        $this->assertSame(1, $outlook['production']['runs']);

        $product = collect($outlook['products'])->firstWhere('product_variant_id', $variant->id);

        $this->assertSame(4, $product['units_sold']);
        // Everywhere, not just the main branch: four left there and two at the
        // kiosk are both stock the bakery is holding.
        $this->assertSame(6, $product['stock_on_hand']);
        $this->assertTrue($product['has_recipe']);

        $ingredient = collect($outlook['ingredients'])->firstWhere('ingredient_id', $flour->id);

        $this->assertSame(35.0, $ingredient['stock']);
    }

    public function test_a_cancelled_pre_order_moves_neither_stock_nor_money(): void
    {
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '1.500',
        ]);

        $this->shelve($variant, $this->kiosk, 6);

        $order = $this->placeOrder($variant, 4);

        $this->advance($order, [OrderStatus::Confirmed]);

        Livewire::actingAs($this->cashier)
            ->test('pages::employee.orders.show', ['order' => $order->fresh()])
            ->call('cancel');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseCount('sales', 0);

        // The cakes are still on the shelf, and nothing downstream counts the
        // order as business done.
        $this->assertSame(6, $variant->stockAt($this->kiosk));

        $report = Livewire::actingAs($this->manager)->test('pages::employee.reports.sales');

        $this->assertSame(0, $report->instance()->takings());
        $this->assertSame(0, $report->instance()->units());

        $outlook = Livewire::actingAs($this->manager)
            ->test('pages::employee.forecast')
            ->instance()
            ->outlook();

        $this->assertSame(0, $outlook['demand']['units_sold']);
        $this->assertSame(6, collect($outlook['products'])
            ->firstWhere('product_variant_id', $variant->id)['stock_on_hand']);
    }

    public function test_a_short_preparation_moves_and_reports_only_what_was_set_aside(): void
    {
        $variant = $this->producible('Ube Cake', 'Large (10x14)', '990.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '2.000',
        ]);

        $this->shelve($variant, $this->main, 10);

        Livewire::actingAs($this->manager)
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 8]])
            ->call('save')
            ->assertHasNoErrors();

        $restock = Restock::query()->firstOrFail();
        $item = $restock->items()->firstOrFail();

        // The bake came up short, so five travel where eight were asked for.
        Livewire::actingAs($this->baker)
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->call('startPreparing')
            ->set("prepared.{$item->id}", '5')
            ->call('savePrepared')
            ->assertHasNoErrors()
            ->call('deliver');

        $this->assertSame(RestockStatus::Delivered, $restock->fresh()->status);
        $this->assertSame(5, $variant->stockAt($this->main));
        $this->assertSame(5, $variant->stockAt($this->kiosk));
        $this->assertSame(8, $item->fresh()->quantity_requested);
        $this->assertSame(5, $item->fresh()->quantity_prepared);

        // The ledger and the report have to agree on the five. A report that
        // counted the request instead would credit the kiosk with goods that
        // never left the main branch.
        $this->assertSame(5, $this->ledgerBalance($variant, $this->kiosk));
        $this->assertSame(5, $this->outletRows()['Mall Kiosk']['received']);

        $this->assertSame(-5, (int) ProductStockMovement::query()
            ->where('restock_id', $restock->id)
            ->where('outlet_id', $this->main->id)
            ->sum('quantity'));
    }

    public function test_voiding_a_sale_puts_the_goods_back_and_takes_the_money_off_the_books(): void
    {
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '1.500',
        ]);

        $this->shelve($variant, $this->kiosk, 6);

        $order = $this->placeOrder($variant, 4);

        $this->advance($order, [
            OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed,
        ]);

        $sale = Sale::query()->firstOrFail();

        $this->assertSame(2, $variant->stockAt($this->kiosk));

        Livewire::actingAs($this->cashier)
            ->test('pages::employee.sales.index')
            ->call('void', $sale->id);

        $this->assertSame(SaleStatus::Voided, $sale->fresh()->status);
        $this->assertNotNull($sale->fresh()->voided_at);

        // The cakes never left, so they go back on the shelf — as a new entry,
        // because the ledger is append-only.
        $this->assertSame(6, $variant->stockAt($this->kiosk));
        $this->assertSame(2, $sale->stockMovements()->count());

        // The order stays completed: voiding undoes the takings, not the
        // history of what the customer was told.
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        $report = Livewire::actingAs($this->manager)->test('pages::employee.reports.sales');

        $this->assertSame(0, $report->instance()->takings());
        $this->assertSame(0, $report->instance()->salesCount());
        $this->assertSame(0, $report->instance()->units());
        $this->assertSame(0, $this->outletRows()['Mall Kiosk']['takings']);

        $dashboard = Livewire::actingAs($this->manager)->test('pages::employee.dashboard');

        $this->assertSame('0.00', $dashboard->instance()->salesToday());

        $outlook = Livewire::actingAs($this->manager)
            ->test('pages::employee.forecast')
            ->instance()
            ->outlook();

        $this->assertSame(0, $outlook['demand']['units_sold']);
        $this->assertSame(0, $outlook['demand']['takings']);
        $this->assertSame(6, collect($outlook['products'])
            ->firstWhere('product_variant_id', $variant->id)['stock_on_hand']);
    }

    public function test_counter_sales_and_collected_pre_orders_land_in_the_same_books(): void
    {
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '1.500',
        ]);

        $this->shelve($variant, $this->kiosk, 10);

        // One walk-in at the counter...
        Livewire::actingAs($this->cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 2]])
            ->call('save')
            ->assertHasNoErrors();

        // ...and one pre-order collected the same day.
        $order = $this->placeOrder($variant, 4);

        $this->advance($order, [
            OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed,
        ]);

        $this->assertSame(2, Sale::query()->count());
        $this->assertSame(1, Sale::query()->whereNull('order_id')->count());
        $this->assertSame(4, $variant->stockAt($this->kiosk));

        // Both took goods off the same shelf through the same ledger.
        $this->assertSame(-6, (int) ProductStockMovement::query()
            ->where('type', ProductStockMovementType::Sale)
            ->sum('quantity'));

        $report = Livewire::actingAs($this->manager)->test('pages::employee.reports.sales');

        $this->assertSame(300000, $report->instance()->takings());
        $this->assertSame(2, $report->instance()->salesCount());
        $this->assertSame(6, $report->instance()->units());
        $this->assertSame(150000, $report->instance()->averageSale());

        $byProduct = $report->instance()->byProduct();

        $this->assertCount(1, $byProduct);
        $this->assertSame(6, $byProduct->first()['units']);

        $outlook = Livewire::actingAs($this->manager)
            ->test('pages::employee.forecast')
            ->instance()
            ->outlook();

        $this->assertSame(6, $outlook['demand']['units_sold']);
        $this->assertSame(2, $outlook['demand']['sales']);
        $this->assertSame(300000, $outlook['demand']['takings']);
        $this->assertSame(6, collect($outlook['products'])
            ->firstWhere('product_variant_id', $variant->id)['units_sold']);
    }

    public function test_withdrawing_a_product_stops_the_outlook_projecting_it_but_keeps_its_history(): void
    {
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '1.500',
        ]);
        $staying = $this->producible('Pastillas', 'Box of 12', '180.00', 1, [
            $this->stocked('White Sugar', '30.000')->id => '0.800',
        ]);

        $this->shelve($variant, $this->kiosk, 10);

        Livewire::actingAs($this->cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 4]])
            ->call('save')
            ->assertHasNoErrors();

        // The manager then takes the cake off the menu.
        Livewire::actingAs($this->manager)
            ->test('pages::employee.products.manage', ['product' => $variant->product])
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($variant->product->fresh()->is_active);

        // It leaves the catalogue the customer sees and the counter can ring up.
        Livewire::test('pages::storefront.products')->assertDontSee('Ube Cake');

        Livewire::actingAs($this->cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasErrors('lines');

        // What it sold while it was on the menu still happened, so the reports
        // and the outlook's totals keep counting it.
        $report = Livewire::actingAs($this->manager)->test('pages::employee.reports.sales');

        $this->assertSame(200000, $report->instance()->takings());
        $this->assertSame(4, $report->instance()->units());
        $this->assertSame('Ube Cake', $report->instance()->byProduct()->first()['product']);

        $outlook = Livewire::actingAs($this->manager)
            ->test('pages::employee.forecast')
            ->instance()
            ->outlook();

        $this->assertSame(4, $outlook['demand']['units_sold']);
        $this->assertSame(200000, $outlook['demand']['takings']);

        // But nothing suggests baking more of it: "what to consider baking"
        // reads the same sellable catalogue as the counter and the storefront.
        $sizes = collect($outlook['products'])->pluck('product_variant_id');

        $this->assertFalse($sizes->contains($variant->id));
        $this->assertTrue($sizes->contains($staying->id));
    }

    public function test_the_chain_cannot_be_walked_by_any_one_role(): void
    {
        $variant = $this->producible('Ube Cake', 'Round (7x3)', '500.00', 1, [
            $this->stocked('All-Purpose Flour', '50.000')->id => '1.500',
        ]);

        $this->shelve($variant, $this->main, 10);

        $order = $this->placeOrder($variant, 1);

        // The Cashier owns the order and the till, and nothing upstream of it.
        $this->actingAs($this->cashier)->get(route('employee.orders.show', $order))->assertOk();
        $this->actingAs($this->cashier)->get(route('employee.production.runs.index'))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('employee.restocks.index'))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('employee.forecast'))->assertForbidden();

        // The Baker bakes and sends the goods out, but never touches an order
        // or the money taken for one.
        $this->actingAs($this->baker)->get(route('employee.production.runs.index'))->assertOk();
        $this->actingAs($this->baker)->get(route('employee.restocks.index'))->assertOk();
        $this->actingAs($this->baker)->get(route('employee.orders.show', $order))->assertForbidden();
        $this->actingAs($this->baker)->get(route('employee.sales.index'))->assertForbidden();

        // Scheduling a restock is the Administrator's call, even though the
        // Baker is the one who prepares and delivers it.
        $this->actingAs($this->baker)->get(route('employee.restocks.create'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('employee.restocks.create'))->assertOk();

        // And the handoffs hold in the components themselves, not just on the
        // routes that reach them: the Baker never gets far enough to press
        // anything on the order screen.
        Livewire::actingAs($this->baker)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
    }
}
