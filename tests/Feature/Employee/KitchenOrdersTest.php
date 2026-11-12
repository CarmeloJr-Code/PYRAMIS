<?php

namespace Tests\Feature\Employee;

use App\Actions\CountWorkNeedingAttention;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\OrderReadyForPickup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Baker's part of a customer order (BR-015): reading the paid orders, and
 * moving one to Preparing and to Ready — and nothing else.
 */
class KitchenOrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order carrying one line, in the given state.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function order(OrderStatus $status, array $attributes = []): Order
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
     * The queue as a baker sees it.
     */
    private function queue(): Testable
    {
        return Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.index');
    }

    /**
     * An order opened by a baker.
     */
    private function opened(Order $order): Testable
    {
        return Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.show', ['order' => $order]);
    }

    public function test_the_queue_shows_a_baker_the_orders_to_make_and_no_others(): void
    {
        $confirmed = $this->order(OrderStatus::Confirmed);
        $preparing = $this->order(OrderStatus::Preparing);
        $pending = $this->order(OrderStatus::Pending);
        $ready = $this->order(OrderStatus::Ready);
        $completed = $this->order(OrderStatus::Completed);
        $cancelled = $this->order(OrderStatus::Cancelled);

        $this->queue()
            ->assertOk()
            ->assertSeeText($confirmed->reference)
            ->assertSeeText($preparing->reference)
            ->assertDontSeeText($pending->reference)
            ->assertDontSeeText($ready->reference)
            ->assertDontSeeText($completed->reference)
            ->assertDontSeeText($cancelled->reference);
    }

    public function test_a_baker_can_look_back_at_the_orders_marked_ready(): void
    {
        $confirmed = $this->order(OrderStatus::Confirmed);
        $ready = $this->order(OrderStatus::Ready);

        $this->queue()
            ->set('statusFilter', OrderStatus::Ready->value)
            ->assertSeeText($ready->reference)
            ->assertDontSeeText($confirmed->reference);
    }

    /**
     * @return array<string, array{0: OrderStatus}>
     */
    public static function outsideTheKitchenProvider(): array
    {
        return [
            'pending' => [OrderStatus::Pending],
            'completed' => [OrderStatus::Completed],
            'cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('outsideTheKitchenProvider')]
    public function test_a_baker_cannot_filter_to_a_state_outside_the_kitchen(OrderStatus $status): void
    {
        $order = $this->order($status);

        // The filter lives in the URL, which anyone can edit.
        Livewire::withQueryParams(['status' => $status->value])
            ->actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.index')
            ->assertOk()
            ->assertDontSeeText($order->reference);
    }

    #[DataProvider('outsideTheKitchenProvider')]
    public function test_a_search_does_not_find_a_baker_an_order_outside_the_kitchen(OrderStatus $status): void
    {
        $order = $this->order($status, ['customer_name' => 'Maria Santos']);

        $this->queue()
            ->set('search', 'Maria')
            ->assertDontSeeText($order->reference);
    }

    public function test_the_queue_keeps_the_phone_and_the_total_from_a_baker(): void
    {
        $order = $this->order(OrderStatus::Confirmed, ['customer_name' => 'Maria Santos', 'customer_phone' => '09171234567']);

        $this->queue()
            ->assertSeeText('Maria Santos')
            ->assertDontSeeText('09171234567')
            ->assertDontSeeText('990.00')
            // Nor can the hidden number be searched for.
            ->set('search', '09171234567')
            ->assertDontSeeText($order->reference);
    }

    #[DataProvider('outsideTheKitchenProvider')]
    public function test_a_baker_cannot_open_an_order_outside_the_kitchen(OrderStatus $status): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.orders.show', $this->order($status)))
            ->assertForbidden();
    }

    public function test_an_order_shows_a_baker_what_to_make_and_not_the_payment(): void
    {
        $order = $this->order(OrderStatus::Confirmed, [
            'customer_name' => 'Maria Santos',
            'customer_phone' => '09171234567',
            'customer_email' => 'maria@example.com',
            'notes' => 'Happy birthday Lola',
        ]);
        OrderPayment::factory()->for($order)->verified('990.00')->create(['gcash_reference' => '1234567890123']);

        $this->opened($order)
            ->assertOk()
            ->assertSeeText('Ube Cake')
            ->assertSeeText('Happy birthday Lola')
            ->assertSeeText('Maria Santos')
            ->assertSeeText('Mark as Preparing')
            ->assertDontSeeText('09171234567')
            ->assertDontSeeText('maria@example.com')
            ->assertDontSeeText('GCash payment')
            ->assertDontSeeText('1234567890123')
            ->assertDontSeeText('Cancel order');
    }

    public function test_a_baker_marks_a_confirmed_order_preparing_then_ready(): void
    {
        Notification::fake();

        $order = $this->order(OrderStatus::Confirmed);

        $this->opened($order)->call('advance')->assertSeeText('Mark as Ready for pickup');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
        Notification::assertNothingSent();

        $this->opened($order->fresh())
            ->call('advance')
            ->assertDontSeeText('Mark as')
            ->assertSeeText('The counter hands it over from here.');

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        // The customer is told, whoever marked it (BR-014).
        Notification::assertSentOnDemand(OrderReadyForPickup::class);
    }

    public function test_a_baker_cannot_complete_a_ready_order(): void
    {
        $order = $this->order(OrderStatus::Ready);

        $this->opened($order)->call('advance')->assertForbidden();

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
    }

    #[DataProvider('kitchenProvider')]
    public function test_a_baker_cannot_cancel_an_order(OrderStatus $status): void
    {
        $order = $this->order($status);

        $this->opened($order)->call('cancel')->assertForbidden();

        $this->assertSame($status, $order->fresh()->status);
    }

    /**
     * @return array<string, array{0: OrderStatus}>
     */
    public static function kitchenProvider(): array
    {
        return [
            'confirmed' => [OrderStatus::Confirmed],
            'preparing' => [OrderStatus::Preparing],
            'ready' => [OrderStatus::Ready],
        ];
    }

    public function test_a_baker_cannot_confirm_a_payment_on_an_order_already_open(): void
    {
        $order = $this->order(OrderStatus::Confirmed);
        $page = $this->opened($order);

        // Moved back under them, as far as the component can tell.
        $order->update(['status' => OrderStatus::Pending]);

        $page->set('amount', '990.00')
            ->set('gcash_reference', '1234567890123')
            ->call('confirmPayment')
            ->assertForbidden();

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_the_sidebar_counts_a_baker_the_orders_nobody_has_started(): void
    {
        $this->order(OrderStatus::Confirmed);
        $this->order(OrderStatus::Confirmed);
        $this->order(OrderStatus::Preparing);
        // A payment to check is the counter's work, not the kitchen's.
        OrderPayment::factory()->for($this->order(OrderStatus::Pending))->create();

        $this->assertSame(
            ['orders' => 2],
            app(CountWorkNeedingAttention::class)->handle(User::factory()->baker()->create()),
        );
    }

    public function test_the_sidebar_offers_a_baker_the_orders(): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertSee(route('employee.orders.index'));
    }
}
