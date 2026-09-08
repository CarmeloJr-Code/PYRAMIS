<?php

namespace Tests\Feature\Employee;

use App\Actions\RecordSaleForOrder;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order sitting at Ready with two lines, waiting to be collected.
     */
    protected function readyOrder(): Order
    {
        $order = Order::factory()
            ->status(OrderStatus::Ready)
            ->for(Outlet::factory()->create(['name' => 'Main Branch']))
            ->create();

        $product = Product::factory()->create(['name' => 'Ube Cake']);

        OrderItem::factory()->for($order)
            ->for(ProductVariant::factory()->for($product)->create(['name' => 'Large']))
            ->create(['quantity' => 2, 'unit_price' => '990.00']);

        OrderItem::factory()->for($order)
            ->for(ProductVariant::factory()->for($product)->create(['name' => 'Slice']))
            ->create(['quantity' => 1, 'unit_price' => '70.00']);

        return $order->fresh();
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
    public function test_only_administrators_and_cashiers_see_sales(UserRole $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('employee.sales.index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.sales.index'))->assertRedirect(route('login'));
    }

    public function test_completing_an_order_records_a_sale_snapshotting_its_lines(): void
    {
        $order = $this->readyOrder();
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        $sale = Sale::first();

        $this->assertNotNull($sale);
        $this->assertSame($order->id, $sale->order_id);
        $this->assertSame($order->outlet_id, $sale->outlet_id);
        $this->assertSame($cashier->id, $sale->recorded_by);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertMatchesRegularExpression('/^SL-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/', $sale->reference);

        // Two lines, at the prices the customer was quoted — 2 x 990 + 70.
        $this->assertCount(2, $sale->items);
        $this->assertSame('2050.00', $sale->load('items')->total());
    }

    public function test_the_sale_keeps_its_own_prices_when_the_order_lines_change_later(): void
    {
        $order = $this->readyOrder();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        $sale = Sale::first();

        // Whatever happens to the catalogue afterwards, the sale is the record
        // of money that changed hands.
        ProductVariant::query()->update(['price' => '1.00']);

        $this->assertSame('2050.00', $sale->fresh()->load('items')->total());
    }

    public function test_an_order_cannot_be_billed_twice(): void
    {
        $order = $this->readyOrder();
        $cashier = User::factory()->cashier()->create();

        app(RecordSaleForOrder::class)->handle($order, $cashier);

        $this->expectException(RuntimeException::class);

        app(RecordSaleForOrder::class)->handle($order->fresh(), $cashier);
    }

    public function test_an_order_that_is_not_ready_cannot_be_billed(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $this->expectException(RuntimeException::class);

        app(RecordSaleForOrder::class)->handle($order, User::factory()->cashier()->create());
    }

    public function test_cancelling_an_order_records_no_sale(): void
    {
        $order = $this->readyOrder();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('cancel');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseEmpty('sales');
    }

    public function test_the_daily_total_covers_the_chosen_day_only(): void
    {
        $today = Sale::factory()->create(['sold_at' => now()]);
        SaleItem::factory()->for($today)->create(['quantity' => 2, 'unit_price' => '500.00']);

        $yesterday = Sale::factory()->create(['sold_at' => now()->subDay()]);
        SaleItem::factory()->for($yesterday)->create(['quantity' => 1, 'unit_price' => '999.00']);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.index')
            ->assertSeeText('1,000.00')
            ->assertDontSeeText('999.00');
    }

    public function test_voided_sales_stay_on_record_but_leave_the_total(): void
    {
        $kept = Sale::factory()->create(['sold_at' => now()]);
        SaleItem::factory()->for($kept)->create(['quantity' => 1, 'unit_price' => '300.00']);

        $voided = Sale::factory()->voided()->create(['sold_at' => now()]);
        SaleItem::factory()->for($voided)->create(['quantity' => 1, 'unit_price' => '700.00']);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.index')
            // The voided sale is still listed...
            ->assertSeeText($voided->reference)
            // ...but the day's takings are 300, not 1,000.
            ->assertSeeText('300.00');

        $this->assertSame('300.00', number_format(
            Sale::completed()->with('items')->get()->sum(fn (Sale $sale): int => $sale->totalInCentavos()) / 100,
            2, '.', ''
        ));
    }

    public function test_a_cashier_can_void_a_sale_and_it_is_never_deleted(): void
    {
        $sale = Sale::factory()->create(['sold_at' => now()]);
        SaleItem::factory()->for($sale)->create(['quantity' => 1, 'unit_price' => '450.00']);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.index')
            ->call('void', $sale->id);

        $sale->refresh();

        $this->assertSame(SaleStatus::Voided, $sale->status);
        $this->assertNotNull($sale->voided_at);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_voiding_twice_leaves_the_first_void_intact(): void
    {
        $sale = Sale::factory()->voided()->create(['sold_at' => now()]);
        $voidedAt = $sale->voided_at;

        $sale->void();

        $this->assertEquals($voidedAt, $sale->fresh()->voided_at);
    }
}
