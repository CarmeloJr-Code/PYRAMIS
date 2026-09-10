<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
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
    protected function variant(string $price = '460.00'): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->create())
            ->create(['price' => $price, 'is_available' => true]);
    }

    /**
     * Fill in the customer half of the checkout form.
     */
    protected function withCustomerDetails(Testable $component): Testable
    {
        return $component
            ->set('customer_name', 'Ana Reyes')
            ->set('customer_phone', '09171234567')
            ->set('outlet_id', $this->outlet->id)
            ->set('pickup_at', now()->addDay()->format('Y-m-d\TH:i'));
    }

    /**
     * Place one complete, valid pre-order.
     */
    protected function placeOrder(): Testable
    {
        Session::put('cart', [$this->variant()->id => 1]);

        return $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit');
    }

    public function test_a_guest_places_a_multi_item_pre_order(): void
    {
        $large = $this->variant('990.00');
        $cup = $this->variant('70.00');

        Session::put('cart', [$large->id => 2, $cup->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('Ana Reyes', $order->customer_name);
        $this->assertSame($this->outlet->id, $order->outlet_id);
        $this->assertMatchesRegularExpression('/^PY-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/', $order->reference);

        $this->assertCount(2, $order->items);
        $this->assertSame('2050.00', $order->load('items')->total());

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_variant_id' => $large->id,
            'quantity' => 2,
            'unit_price' => '990.00',
        ]);

        $this->assertEmpty(session('cart', []));
    }

    public function test_the_line_price_is_a_snapshot_taken_at_order_time(): void
    {
        $variant = $this->variant('460.00');

        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit')
            ->assertHasNoErrors();

        $variant->update(['price' => '999.00']);

        $this->assertSame('460.00', Order::first()->items->first()->unit_price);
    }

    public function test_it_rejects_an_empty_order(): void
    {
        Session::put('cart', []);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit')
            ->assertHasErrors('cart');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_missing_customer_details(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        Livewire::test('pages::storefront.order')
            ->set('customer_name', '')
            ->set('customer_phone', '')
            ->set('outlet_id', null)
            ->set('pickup_at', '')
            ->call('submit')
            ->assertHasErrors(['customer_name', 'customer_phone', 'outlet_id', 'pickup_at']);

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_a_pickup_time_in_the_past(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_at', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('submit')
            ->assertHasErrors('pickup_at');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_a_closed_outlet(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('outlet_id', Outlet::factory()->inactive()->create()->id)
            ->call('submit')
            ->assertHasErrors('outlet_id');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_a_variant_that_is_no_longer_available(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $variant->update(['is_available' => false]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit')
            ->assertHasErrors('cart');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_a_variant_whose_product_was_withdrawn(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $variant->product->update(['is_active' => false]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->call('submit')
            ->assertHasErrors('cart');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_refuses_a_sixth_pre_order_within_the_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->placeOrder()->assertHasNoErrors();
        }

        $this->placeOrder()->assertHasErrors('throttle');

        $this->assertSame(5, Order::count());
    }

    public function test_a_refused_order_costs_nothing_against_the_limit(): void
    {
        $variant = $this->variant();

        // Ten submissions of a form that was never going to be accepted. None
        // of them becomes an order, so none of them should spend the allowance.
        for ($i = 0; $i < 10; $i++) {
            Session::put('cart', [$variant->id => 1]);

            Livewire::test('pages::storefront.order')
                ->call('submit')
                ->assertHasErrors('customer_name');
        }

        $this->placeOrder()->assertHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_the_limit_lifts_once_the_hour_is_up(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->placeOrder()->assertHasNoErrors();
        }

        $this->placeOrder()->assertHasErrors('throttle');

        $this->travel(61)->minutes();

        $this->placeOrder()->assertHasNoErrors();

        $this->assertSame(6, Order::count());
    }

    public function test_the_limit_follows_the_browser_not_the_whole_storefront(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->placeOrder()->assertHasNoErrors();
        }

        $this->placeOrder()->assertHasErrors('throttle');

        // The next customer through the door is a different browser, and one
        // shop's worth of orders is not their fault.
        Session::setId(Str::random(40));

        $this->placeOrder()->assertHasNoErrors();

        $this->assertSame(6, Order::count());
    }

    public function test_removing_the_last_line_empties_the_cart(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 2]);

        Livewire::test('pages::storefront.order')
            ->call('remove', $variant->id);

        $this->assertEmpty(session('cart', []));
    }
}
