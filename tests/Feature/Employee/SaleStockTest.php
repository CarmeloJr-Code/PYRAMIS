<?php

namespace Tests\Feature\Employee;

use App\Actions\RecordSaleForOrder;
use App\Actions\VoidSale;
use App\Enums\OrderStatus;
use App\Enums\ProductStockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleStockTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->create(['name' => 'Mall Kiosk']);
    }

    /**
     * A sellable size, with the given number of units on this outlet's shelf.
     */
    private function stocked(int $units, string $product = 'Ube Cake', string $size = 'Round (7x3)'): ProductVariant
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size, 'price' => '500.00', 'is_available' => true]);

        if ($units !== 0) {
            ProductStockMovement::factory()
                ->for($variant, 'productVariant')
                ->for($this->outlet)
                ->quantity($units)
                ->create();
        }

        return $variant;
    }

    public function test_a_counter_sale_takes_the_goods_off_the_outlet_shelf(): void
    {
        $variant = $this->stocked(10);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 3]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7, $variant->stockAt($this->outlet));

        $sale = Sale::first();

        $this->assertDatabaseHas('product_stock_movements', [
            'product_variant_id' => $variant->id,
            'outlet_id' => $this->outlet->id,
            'type' => ProductStockMovementType::Sale->value,
            'quantity' => -3,
            'sale_id' => $sale->id,
            'recorded_by' => $cashier->id,
        ]);
    }

    public function test_collecting_a_pre_order_takes_it_off_the_pickup_outlet_shelf(): void
    {
        $variant = $this->stocked(6);

        $order = Order::factory()->status(OrderStatus::Ready)->for($this->outlet)->create();
        OrderItem::factory()->for($order)->for($variant, 'productVariant')
            ->create(['quantity' => 2, 'unit_price' => '500.00']);

        $sale = app(RecordSaleForOrder::class)->handle($order->fresh(), User::factory()->cashier()->create());

        $this->assertSame(4, $variant->stockAt($this->outlet));
        $this->assertSame(1, $sale->stockMovements()->count());
    }

    public function test_a_sale_may_take_the_shelf_negative(): void
    {
        // Nothing recorded on the shelf, but the cake went over the counter.
        // Refusing would lose the takings rather than fix the count.
        $variant = $this->stocked(1);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 4]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(-3, $variant->stockAt($this->outlet));
        $this->assertSame(1, Sale::count());
    }

    public function test_a_sale_only_touches_the_outlet_it_was_rung_up_at(): void
    {
        $elsewhere = Outlet::factory()->create(['name' => 'Main Branch']);
        $variant = $this->stocked(10);

        ProductStockMovement::factory()
            ->for($variant, 'productVariant')
            ->for($elsewhere)
            ->quantity(5)
            ->create();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 2]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(8, $variant->stockAt($this->outlet));
        $this->assertSame(5, $variant->stockAt($elsewhere));
    }

    public function test_voiding_a_sale_puts_the_goods_back(): void
    {
        $variant = $this->stocked(10);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 3]])
            ->call('save');

        $sale = Sale::first();
        $this->assertSame(7, $variant->stockAt($this->outlet));

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.index')
            ->call('void', $sale->id);

        $this->assertTrue($sale->fresh()->isVoided());
        $this->assertSame(10, $variant->stockAt($this->outlet));

        // Both halves stay on the record against the same sale.
        $this->assertSame(2, $sale->stockMovements()->count());
        $this->assertDatabaseHas('product_stock_movements', [
            'sale_id' => $sale->id,
            'quantity' => 3,
            'note' => 'Sale voided',
        ]);
    }

    public function test_voiding_twice_hands_the_goods_back_only_once(): void
    {
        $variant = $this->stocked(10);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 2]])
            ->call('save');

        $sale = Sale::first();
        $voider = app(VoidSale::class);

        $voider->handle($sale, $cashier);
        $voider->handle($sale->fresh(), $cashier);

        $this->assertSame(10, $variant->stockAt($this->outlet));
        $this->assertSame(2, $sale->stockMovements()->count());
    }

    public function test_voiding_a_sale_that_never_moved_stock_credits_nothing(): void
    {
        // A sale from before the shelf existed took nothing off it, so voiding
        // it must not hand back goods that never moved.
        $sale = Sale::factory()->for($this->outlet)->create();

        app(VoidSale::class)->handle($sale, User::factory()->cashier()->create());

        $this->assertTrue($sale->fresh()->isVoided());
        $this->assertSame(0, ProductStockMovement::count());
    }
}
