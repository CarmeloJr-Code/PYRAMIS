<?php

namespace Tests\Feature\Employee;

use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CounterSaleTest extends TestCase
{
    use RefreshDatabase;

    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->create();
    }

    /**
     * A sellable size of a published product.
     */
    protected function variant(string $price = '460.00', string $product = 'Ube Cake'): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['price' => $price, 'is_available' => true]);
    }

    /**
     * @return array<string, array{0: UserRole, 1: bool}>
     */
    public static function roleProvider(): array
    {
        return [
            'administrator' => [UserRole::Administrator, true],
            'cashier' => [UserRole::Cashier, true],
            'baker' => [UserRole::Baker, false],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_only_administrators_and_cashiers_can_record_a_counter_sale(UserRole $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('employee.sales.create'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.sales.create'))->assertRedirect(route('login'));
    }

    public function test_the_component_itself_refuses_a_baker(): void
    {
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.sales.create')
            ->assertForbidden();
    }

    public function test_a_cashier_records_a_two_line_counter_sale(): void
    {
        $large = $this->variant('990.00', 'Ube Cake');
        $slice = $this->variant('70.00', 'Ube Custard Cake');
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [
                ['product_variant_id' => $large->id, 'quantity' => 2],
                ['product_variant_id' => $slice->id, 'quantity' => 1],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $sale = Sale::first();

        $this->assertNotNull($sale);
        $this->assertNull($sale->order_id, 'A counter sale has no originating pre-order.');
        $this->assertSame($this->outlet->id, $sale->outlet_id);
        $this->assertSame($cashier->id, $sale->recorded_by);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertMatchesRegularExpression('/^SL-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/', $sale->reference);

        // 2 x 990 + 70
        $this->assertCount(2, $sale->items);
        $this->assertSame('2050.00', $sale->load('items')->total());
    }

    public function test_the_price_is_snapshotted_at_the_time_of_sale(): void
    {
        $variant = $this->variant('460.00');

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasNoErrors();

        $variant->update(['price' => '9999.00']);

        $this->assertSame('460.00', Sale::first()->items->first()->unit_price);
    }

    public function test_it_rejects_a_sale_with_no_outlet_or_no_item(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', null)
            ->set('lines', [['product_variant_id' => null, 'quantity' => 1]])
            ->call('save')
            ->assertHasErrors(['outlet_id', 'lines.0.product_variant_id']);

        $this->assertDatabaseEmpty('sales');
    }

    public function test_it_rejects_a_closed_outlet(): void
    {
        $variant = $this->variant();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', Outlet::factory()->inactive()->create()->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasErrors('outlet_id');

        $this->assertDatabaseEmpty('sales');
    }

    public function test_it_rejects_a_quantity_below_one(): void
    {
        $variant = $this->variant();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 0]])
            ->call('save')
            ->assertHasErrors('lines.0.quantity');

        $this->assertDatabaseEmpty('sales');
    }

    public function test_it_rejects_the_same_item_on_two_lines(): void
    {
        $variant = $this->variant();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
                ['product_variant_id' => $variant->id, 'quantity' => 2],
            ])
            ->call('save')
            ->assertHasErrors('lines.0.product_variant_id');

        $this->assertDatabaseEmpty('sales');
    }

    public function test_it_rejects_an_unavailable_variant(): void
    {
        $variant = $this->variant();
        $variant->update(['is_available' => false]);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseEmpty('sales');
    }

    public function test_it_rejects_a_variant_whose_product_was_withdrawn(): void
    {
        $variant = $this->variant();
        $variant->product->update(['is_active' => false]);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseEmpty('sales');
    }

    public function test_the_daily_total_sums_counter_and_order_sales_together(): void
    {
        $cashier = User::factory()->cashier()->create();

        // An order-sourced sale of 500.
        $order = Order::factory()->status(OrderStatus::Ready)->for($this->outlet)->create();
        OrderItem::factory()->for($order)
            ->for($this->variant('500.00', 'Chobe Cake'))
            ->create(['quantity' => 1, 'unit_price' => '500.00']);

        Livewire::actingAs($cashier)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        // A counter sale of 300.
        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $this->variant('300.00', 'Tote Bag')->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Sale::count());

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.index')
            ->assertSeeText('800.00');
    }

    public function test_voiding_a_counter_sale_removes_it_from_the_total(): void
    {
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.create')
            ->set('outlet_id', $this->outlet->id)
            ->set('lines', [['product_variant_id' => $this->variant('450.00')->id, 'quantity' => 1]])
            ->call('save')
            ->assertHasNoErrors();

        $sale = Sale::first();

        Livewire::actingAs($cashier)
            ->test('pages::employee.sales.index')
            ->call('void', $sale->id)
            ->assertSeeText('0.00');

        $this->assertSame(SaleStatus::Voided, $sale->fresh()->status);
        $this->assertDatabaseCount('sales', 1);
    }
}
