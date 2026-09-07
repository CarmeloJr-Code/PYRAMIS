<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order with one line, for a named product and outlet.
     */
    protected function order(string $productName = 'Ube Cake', ?OrderStatus $status = null): Order
    {
        $order = Order::factory()
            ->status($status ?? OrderStatus::Pending)
            ->for(Outlet::factory()->create(['name' => 'Main Branch']))
            ->create();

        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $productName]))
            ->create(['name' => 'Large', 'price' => '990.00']);

        OrderItem::factory()->for($order)->for($variant)->create([
            'quantity' => 2,
            'unit_price' => '990.00',
        ]);

        return $order->fresh();
    }

    public function test_a_guest_can_track_an_order_by_reference(): void
    {
        $order = $this->order();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText($order->reference)
            ->assertSeeText('Ube Cake')
            ->assertSeeText('Main Branch')
            ->assertSeeText('Pending')
            ->assertSeeText('1,980.00');
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->get(route('orders.show', ['order' => 'PY-2222222222']))->assertNotFound();
    }

    public function test_the_status_shown_is_the_stored_one(): void
    {
        $order = $this->order(status: OrderStatus::Ready);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('Ready for pickup');
    }

    public function test_it_never_exposes_another_order_or_the_workspace(): void
    {
        // BR-012 — the tracking page is a customer surface and must not leak
        // anything employee-side, nor any other customer's order.
        $mine = $this->order('Ube Cake');
        $theirs = $this->order('Chocolate Cake');

        $this->get(route('orders.show', $mine))
            ->assertOk()
            ->assertDontSeeText($theirs->reference)
            ->assertDontSeeText($theirs->customer_name)
            ->assertDontSee(route('employee.dashboard'))
            ->assertDontSee(route('employee.outlets.index'));
    }
}
