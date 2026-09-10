<?php

namespace Tests\Feature\Employee;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order carrying one line, in the given state.
     */
    protected function order(OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()
            ->status($status)
            ->for(Outlet::factory()->create(['name' => 'Main Branch']))
            ->create();

        OrderItem::factory()
            ->for($order)
            ->for(ProductVariant::factory()->for(Product::factory()->create(['name' => 'Ube Cake']))->create())
            ->create(['quantity' => 1, 'unit_price' => '990.00']);

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
    public function test_only_administrators_and_cashiers_reach_the_queue(UserRole $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('employee.orders.index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    #[DataProvider('roleProvider')]
    public function test_only_administrators_and_cashiers_open_an_order(UserRole $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('employee.orders.show', $this->order()));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.orders.index'))->assertRedirect(route('login'));
    }

    public function test_the_component_itself_refuses_a_baker(): void
    {
        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.index')
            ->assertForbidden();
    }

    public function test_the_queue_shows_open_orders_and_hides_finished_ones(): void
    {
        $open = $this->order(OrderStatus::Pending);
        $done = $this->order(OrderStatus::Completed);
        $void = $this->order(OrderStatus::Cancelled);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.orders.index'))
            ->assertOk()
            ->assertSeeText($open->reference)
            ->assertDontSeeText($done->reference)
            ->assertDontSeeText($void->reference);
    }

    public function test_the_queue_can_be_filtered_to_a_finished_state(): void
    {
        $open = $this->order(OrderStatus::Pending);
        $done = $this->order(OrderStatus::Completed);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->set('statusFilter', OrderStatus::Completed->value)
            ->assertSeeText($done->reference)
            ->assertDontSeeText($open->reference);
    }

    public function test_a_cashier_walks_an_order_through_the_whole_workflow(): void
    {
        $order = $this->order(OrderStatus::Pending);
        $cashier = User::factory()->cashier()->create();

        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed] as $expected) {
            Livewire::actingAs($cashier)
                ->test('pages::employee.orders.show', ['order' => $order->fresh()])
                ->call('advance');

            $this->assertSame($expected, $order->fresh()->status);
        }
    }

    public function test_a_cashier_can_cancel_an_open_order(): void
    {
        $order = $this->order(OrderStatus::Preparing);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('cancel');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_completed_order_cannot_be_advanced_or_cancelled(): void
    {
        $order = $this->order(OrderStatus::Completed);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance')
            ->call('cancel');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_revived(): void
    {
        $order = $this->order(OrderStatus::Cancelled);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_the_customer_sees_the_status_the_cashier_set(): void
    {
        // The whole point of the slice: the loop closes back to the customer.
        $order = $this->order(OrderStatus::Preparing);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        $this->get(route('orders.show', $order->fresh()))
            ->assertOk()
            ->assertSeeText('Ready for pickup');
    }

    public function test_the_item_table_does_not_cost_a_query_a_line(): void
    {
        // Strict models turn a lazy load into an exception, but only while they
        // are switched on. This says the same thing in a way that outlives the
        // setting: the lines are fetched together, so drawing five costs what
        // drawing one costs.
        $this->assertSame(
            $this->queriesRenderingAnOrderOf(1),
            $this->queriesRenderingAnOrderOf(5),
        );
    }

    /**
     * Count the queries it takes to open an order carrying the given number of
     * lines, advance it, and draw the result.
     *
     * Advancing matters: mount() runs once, and everything after it works from
     * a model Livewire re-resolved and refreshed.
     */
    private function queriesRenderingAnOrderOf(int $lines): int
    {
        $order = Order::factory()
            ->status(OrderStatus::Pending)
            ->for(Outlet::factory()->create())
            ->create();

        // A distinct size per line: an order may not carry the same one twice.
        for ($line = 0; $line < $lines; $line++) {
            OrderItem::factory()
                ->for($order)
                ->for(ProductVariant::factory()->for(Product::factory()->create())->create())
                ->create(['quantity' => 1, 'unit_price' => '990.00']);
        }

        $component = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order->fresh()]);

        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $component->call('advance')->assertOk();

        return $count;
    }
}
