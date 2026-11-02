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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order carrying one line, in the given state.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function order(OrderStatus $status = OrderStatus::Pending, array $attributes = []): Order
    {
        $order = Order::factory()
            ->status($status)
            ->for(Outlet::factory()->create(['name' => 'Main Branch']))
            ->create($attributes);

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

    /**
     * Completed orders with a known pickup order, so which page a reference
     * lands on is not a matter of luck.
     *
     * @return Collection<int, Order>
     */
    private function finishedOrders(int $count): Collection
    {
        $outlet = Outlet::factory()->create();

        return collect(range(1, $count))->map(fn (int $day): Order => Order::factory()
            ->status(OrderStatus::Completed)
            ->for($outlet)
            ->create(['pickup_at' => now()->addDays($day)]));
    }

    public function test_the_finished_list_is_paged_rather_than_drawn_whole(): void
    {
        // The open queue stays short by itself. Asking for finished orders asks
        // for every one the shop has ever taken, and that list only grows.
        $orders = $this->finishedOrders(26);

        $component = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->set('statusFilter', OrderStatus::Completed->value);

        $component
            ->assertSeeText($orders->first()->reference)
            ->assertDontSeeText($orders->last()->reference);

        $component
            ->call('gotoPage', 2)
            ->assertSeeText($orders->last()->reference)
            ->assertDontSeeText($orders->first()->reference);
    }

    public function test_changing_the_filter_starts_the_new_list_at_the_top(): void
    {
        $open = $this->order(OrderStatus::Pending);
        $this->finishedOrders(26);

        $component = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->set('statusFilter', OrderStatus::Completed->value)
            ->call('gotoPage', 2);

        // The open queue is one page long. Asked for its second page — which is
        // what a filter change would do if it left the page number alone — it
        // would come back empty.
        $component
            ->set('statusFilter', '')
            ->assertSeeText($open->reference);
    }

    /**
     * Two open orders whose customers share nothing a search could catch.
     *
     * @return array{0: Order, 1: Order}
     */
    private function twoCustomers(): array
    {
        return [
            $this->order(attributes: ['customer_name' => 'Maria Santos', 'customer_phone' => '09171234567']),
            $this->order(attributes: ['customer_name' => 'Jose Rizal', 'customer_phone' => '09289998888']),
        ];
    }

    /**
     * Search the queue as a cashier.
     */
    private function search(string $typed, string $statusFilter = ''): Testable
    {
        return Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->set('statusFilter', $statusFilter)
            ->set('search', $typed);
    }

    public function test_a_reference_read_out_loosely_finds_its_order(): void
    {
        [$order, $other] = $this->twoCustomers();
        $suffix = substr($order->reference, 3);

        // Read off a phone screen, copied by hand, or only half of it.
        foreach ([
            Str::lower($order->reference),
            'py '.substr($suffix, 0, 5).' '.substr($suffix, 5),
            $suffix,
            substr($suffix, 2, 5),
        ] as $typed) {
            $this->search($typed)
                ->assertSeeText($order->reference)
                ->assertDontSeeText($other->reference);
        }
    }

    public function test_a_customer_name_finds_their_order_whatever_the_case(): void
    {
        [$order, $other] = $this->twoCustomers();

        $this->search('maria')
            ->assertSeeText($order->reference)
            ->assertDontSeeText($other->reference);
    }

    public function test_part_of_a_phone_number_finds_the_order(): void
    {
        [$order, $other] = $this->twoCustomers();

        foreach (['0917', '1234 567'] as $typed) {
            $this->search($typed)
                ->assertSeeText($order->reference)
                ->assertDontSeeText($other->reference);
        }
    }

    public function test_a_phone_stored_with_spaces_is_still_found(): void
    {
        // Taken before the order form insisted on eleven bare digits.
        $order = $this->order(attributes: ['customer_phone' => '0917 555 0104']);

        $this->search('09175550104')->assertSeeText($order->reference);
    }

    public function test_a_search_finds_an_order_that_has_already_been_collected(): void
    {
        $done = $this->order(OrderStatus::Completed);

        // Under the default open view, which on its own would hide it.
        $this->search($done->reference)->assertSeeText($done->reference);
    }

    public function test_a_search_stays_inside_a_picked_status(): void
    {
        // By name: a missed reference is echoed back in the no-match message.
        $done = $this->order(OrderStatus::Completed, ['customer_name' => 'Maria Santos']);

        $this->search('Maria', OrderStatus::Pending->value)
            ->assertSeeText('No orders match')
            ->assertDontSeeText($done->reference);
    }

    public function test_a_search_with_no_match_says_so(): void
    {
        [$order] = $this->twoCustomers();

        $this->search('PY-2222222222')
            ->assertSeeText('No orders match')
            ->assertDontSeeText($order->reference)
            ->call('$set', 'search', '')
            ->assertSeeText($order->reference);
    }

    public function test_like_wildcards_are_taken_literally_not_as_match_anything(): void
    {
        [$order] = $this->twoCustomers();

        $this->search('M_ria')->assertDontSeeText($order->reference);
        $this->search('%')->assertSeeText($order->reference)->assertDontSeeText('No orders match');
    }

    public function test_the_reference_prefix_alone_does_not_match_every_order(): void
    {
        [$order] = $this->twoCustomers();

        $this->search('PY')->assertDontSeeText($order->reference);
    }

    public function test_changing_the_search_starts_the_new_list_at_the_top(): void
    {
        $orders = $this->finishedOrders(26);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->set('statusFilter', OrderStatus::Completed->value)
            ->call('gotoPage', 2)
            ->set('search', $orders->last()->reference)
            ->assertSeeText($orders->last()->reference);
    }

    /**
     * Two sizes of one product, the way the menu board lists them.
     *
     * @return array{0: ProductVariant, 1: ProductVariant}
     */
    private function twoSizes(): array
    {
        $cake = Product::factory()->create(['name' => 'Ube Cake']);

        return [
            ProductVariant::factory()->for($cake)->create(['name' => 'Large (10x14)']),
            ProductVariant::factory()->for($cake)->create(['name' => 'Round (7x3)']),
        ];
    }

    /**
     * An order for some of one size, picked up at the given time.
     */
    private function pickup(ProductVariant $variant, string $at, int $quantity = 1, OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->status($status)->create(['pickup_at' => $at]);

        OrderItem::factory()->for($order)->for($variant)->create(['quantity' => $quantity, 'unit_price' => $variant->price]);

        return $order;
    }

    /**
     * The queue as a cashier sees it.
     */
    private function queue(): Testable
    {
        return Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index');
    }

    public function test_picking_a_day_shows_only_the_orders_picked_up_that_day(): void
    {
        [$large] = $this->twoSizes();
        $first = $this->pickup($large, '2026-12-05 00:00:00');
        $last = $this->pickup($large, '2026-12-05 23:59:59');
        $before = $this->pickup($large, '2026-12-04 23:59:59');
        $after = $this->pickup($large, '2026-12-06 00:00:00');

        $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->assertSeeText($first->reference)
            ->assertSeeText($last->reference)
            ->assertDontSeeText($before->reference)
            ->assertDontSeeText($after->reference);
    }

    public function test_picking_a_size_shows_only_the_orders_that_carry_it(): void
    {
        [$large, $round] = $this->twoSizes();
        $wanted = $this->pickup($large, '2026-12-05 09:00:00');
        $otherSize = $this->pickup($round, '2026-12-05 09:00:00');

        $this->queue()
            ->set('variantId', $large->id)
            ->assertSeeText($wanted->reference)
            ->assertDontSeeText($otherSize->reference);
    }

    public function test_a_day_and_a_size_together_show_only_where_they_meet(): void
    {
        [$large, $round] = $this->twoSizes();
        $wanted = $this->pickup($large, '2026-12-05 09:00:00');
        $otherDay = $this->pickup($large, '2026-12-06 09:00:00');
        $otherSize = $this->pickup($round, '2026-12-05 09:00:00');

        $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->set('variantId', $large->id)
            ->assertSeeText($wanted->reference)
            ->assertDontSeeText($otherDay->reference)
            ->assertDontSeeText($otherSize->reference);
    }

    public function test_the_tally_counts_each_size_due_that_day(): void
    {
        [$large, $round] = $this->twoSizes();
        $this->pickup($large, '2026-12-05 09:00:00', quantity: 2);
        $this->pickup($large, '2026-12-05 15:00:00', quantity: 3);
        $this->pickup($round, '2026-12-05 10:00:00', quantity: 1);

        // Another day, and orders that are no longer coming: none of them is due.
        $this->pickup($large, '2026-12-06 09:00:00', quantity: 7);
        $this->pickup($large, '2026-12-05 11:00:00', quantity: 5, status: OrderStatus::Completed);
        $this->pickup($round, '2026-12-05 11:00:00', quantity: 4, status: OrderStatus::Cancelled);

        $tally = $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->assertSeeText('Due for pickup on 5 Dec')
            ->instance()
            ->tally;

        $this->assertSame(
            [
                ['Large (10x14)', 5, 2],
                ['Round (7x3)', 1, 1],
            ],
            $tally->map(fn (OrderItem $line): array => [$line->productVariant->name, $line->quantity, (int) $line->order_count])->all(),
        );
    }

    public function test_the_tally_follows_a_picked_status(): void
    {
        [$large] = $this->twoSizes();
        $this->pickup($large, '2026-12-05 09:00:00', quantity: 2);
        $this->pickup($large, '2026-12-05 11:00:00', quantity: 5, status: OrderStatus::Completed);

        $tally = $this->queue()
            ->set('statusFilter', OrderStatus::Completed->value)
            ->set('pickupDay', '2026-12-05')
            ->instance()
            ->tally;

        $this->assertSame([5], $tally->pluck('quantity')->all());
    }

    public function test_the_tally_shows_the_whole_day_whatever_size_is_picked(): void
    {
        [$large, $round] = $this->twoSizes();
        $this->pickup($large, '2026-12-05 09:00:00');
        $this->pickup($round, '2026-12-05 10:00:00');

        $tally = $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->set('variantId', $large->id)
            ->instance()
            ->tally;

        $this->assertCount(2, $tally);
    }

    public function test_there_is_no_tally_until_a_day_is_picked(): void
    {
        [$large] = $this->twoSizes();
        $this->pickup($large, '2026-12-05 09:00:00');

        $this->queue()->assertDontSeeText('Due for pickup on');
    }

    public function test_show_these_on_a_tally_line_picks_its_size(): void
    {
        [$large, $round] = $this->twoSizes();
        $wanted = $this->pickup($large, '2026-12-05 09:00:00');
        $otherSize = $this->pickup($round, '2026-12-05 10:00:00');

        $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->call('$set', 'variantId', $large->id)
            ->assertSet('variantId', $large->id)
            ->assertSeeText($wanted->reference)
            ->assertDontSeeText($otherSize->reference);
    }

    public function test_clearing_brings_back_every_day_and_size(): void
    {
        [$large, $round] = $this->twoSizes();
        $wanted = $this->pickup($large, '2026-12-05 09:00:00');
        $otherSize = $this->pickup($round, '2026-12-06 10:00:00');

        $this->queue()
            ->set('pickupDay', '2026-12-05')
            ->set('variantId', $large->id)
            ->assertDontSeeText($otherSize->reference)
            ->call('clearPickupFilters')
            ->assertSet('pickupDay', '')
            ->assertSet('variantId', 0)
            ->assertSeeText($wanted->reference)
            ->assertSeeText($otherSize->reference);
    }

    public function test_a_day_that_is_not_a_date_is_ignored(): void
    {
        [$large] = $this->twoSizes();
        $order = $this->pickup($large, '2026-12-05 09:00:00');

        foreach (['2026-02-30', 'tomorrow', '05/12/2026'] as $typed) {
            Livewire::withQueryParams(['day' => $typed])
                ->actingAs(User::factory()->cashier()->create())
                ->test('pages::employee.orders.index')
                ->assertOk()
                ->assertSeeText($order->reference)
                ->assertDontSeeText('Due for pickup on');
        }
    }

    public function test_a_size_that_does_not_exist_finds_nothing(): void
    {
        [$large] = $this->twoSizes();
        $order = $this->pickup($large, '2026-12-05 09:00:00');

        Livewire::withQueryParams(['size' => 999999])
            ->actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->assertOk()
            ->assertSeeText('No orders match this filter.')
            ->assertDontSeeText($order->reference);
    }

    public function test_changing_the_day_or_size_starts_the_new_list_at_the_top(): void
    {
        [$large] = $this->twoSizes();
        $orders = $this->finishedOrders(26);
        $first = $orders->first();
        OrderItem::factory()->for($first)->for($large)->create(['quantity' => 1, 'unit_price' => $large->price]);

        // Each filter alone matches one order, so a page two would be empty.
        $this->queue()
            ->set('statusFilter', OrderStatus::Completed->value)
            ->call('gotoPage', 2)
            ->set('pickupDay', $first->pickup_at->toDateString())
            ->assertSeeText($first->reference);

        $this->queue()
            ->set('statusFilter', OrderStatus::Completed->value)
            ->call('gotoPage', 2)
            ->set('variantId', $large->id)
            ->assertSeeText($first->reference);
    }

    public function test_a_baker_is_refused_whatever_day_or_size_is_asked_for(): void
    {
        [$large] = $this->twoSizes();

        Livewire::withQueryParams(['day' => '2026-12-05', 'size' => $large->id])
            ->actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.index')
            ->assertForbidden();
    }

    public function test_a_cashier_walks_an_order_through_the_whole_workflow(): void
    {
        $order = $this->order(OrderStatus::Pending);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->set('amount', '990.00')
            ->set('gcash_reference', '1234567890123')
            ->call('confirmPayment')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);

        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed] as $expected) {
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
            ->assertSeeText('Step 4 of 5');
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
