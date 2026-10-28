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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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
            ->set('pickup_date', now()->addDay()->toDateString())
            ->set('pickup_time', '10:30');
    }

    /**
     * Place one complete, valid pre-order.
     *
     * Takes a variant when the caller places more orders than the factory has
     * distinct size names for, which is eleven.
     */
    protected function placeOrder(?ProductVariant $variant = null): Testable
    {
        Session::put('cart', [($variant ?? $this->variant())->id => 1]);

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

    public function test_a_filled_cart_links_back_to_the_menu_for_more(): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        Livewire::test('pages::storefront.order')
            ->assertSeeText('Add more products')
            ->assertSeeHtml('href="'.route('products.index').'"');
    }

    public function test_an_empty_cart_offers_the_menu_not_more_products(): void
    {
        Session::put('cart', []);

        Livewire::test('pages::storefront.order')
            ->assertSeeText('Browse the menu')
            ->assertDontSeeText('Add more products');
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
            ->set('pickup_date', '')
            ->set('pickup_time', '')
            ->call('submit')
            ->assertHasErrors(['customer_name', 'customer_phone', 'outlet_id', 'pickup_date', 'pickup_time']);

        $this->assertDatabaseEmpty('orders');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mobileProvider(): array
    {
        return [
            'spaced' => ['0917 123 4567'],
            'dashed' => ['0917-123-4567'],
            'international' => ['+63 917 123 4567'],
        ];
    }

    #[DataProvider('mobileProvider')]
    public function test_a_mobile_number_is_stored_one_way_however_it_was_typed(string $typed): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('customer_phone', $typed)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', ['customer_phone' => '09171234567']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAMobileProvider(): array
    {
        return [
            'too short' => ['12345'],
            'not a mobile prefix' => ['0817 123 4567'],
            'letters' => ['abcdefghijk'],
            'one digit too many' => ['091712345678'],
        ];
    }

    #[DataProvider('notAMobileProvider')]
    public function test_it_rejects_a_number_that_is_not_a_philippine_mobile(string $typed): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('customer_phone', $typed)
            ->call('submit')
            ->assertHasErrors('customer_phone');

        $this->assertDatabaseEmpty('orders');
    }

    #[TestWith(['Juan dela Cruz'])]
    #[TestWith(["Ma. O'Neil-Reyes"])]
    #[TestWith(['Niño'])]
    public function test_a_real_name_is_accepted(string $name): void
    {
        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('customer_name', $name)
            ->assertHasNoErrors('customer_name');
    }

    #[TestWith(['R2D2', 'regex'])]
    #[TestWith(['A', 'min'])]
    #[TestWith(['   ', 'required'])]
    public function test_a_name_that_is_not_one_is_refused(string $name, string $rule): void
    {
        Livewire::test('pages::storefront.order')
            ->set('customer_name', $name)
            ->assertHasErrors(['customer_name' => $rule]);
    }

    public function test_a_name_over_the_limit_is_refused(): void
    {
        Livewire::test('pages::storefront.order')
            ->set('customer_name', str_repeat('a', 256))
            ->assertHasErrors(['customer_name' => 'max']);
    }

    public function test_a_phone_of_only_spaces_counts_as_missing(): void
    {
        Livewire::test('pages::storefront.order')
            ->set('customer_phone', '   ')
            ->assertHasErrors(['customer_phone' => 'required']);
    }

    public function test_a_field_is_checked_as_soon_as_it_is_left(): void
    {
        $component = Livewire::test('pages::storefront.order')
            ->set('customer_phone', 'abc')
            ->assertHasErrors('customer_phone');

        $component->set('customer_phone', '09171234567')
            ->assertHasNoErrors('customer_phone');
    }

    public function test_it_rejects_a_bad_email_and_overlong_notes(): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('customer_email', 'not-an-email')
            ->set('notes', str_repeat('a', 1001))
            ->call('submit')
            ->assertHasErrors(['customer_email', 'notes']);

        $this->assertDatabaseEmpty('orders');
    }

    #[TestWith([''])]
    #[TestWith(['abc'])]
    #[TestWith(['2.5'])]
    public function test_a_quantity_that_is_not_a_whole_number_leaves_the_line_alone(string $typed): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 3]);

        Livewire::test('pages::storefront.order')
            ->call('updateQuantity', $variant->id, $typed)
            ->assertOk();

        $this->assertSame([$variant->id => 3], session('cart'));
    }

    public function test_a_typed_quantity_still_updates_the_line(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        Livewire::test('pages::storefront.order')
            ->call('updateQuantity', $variant->id, '4');

        $this->assertSame([$variant->id => 4], session('cart'));
    }

    #[TestWith(['08:00'])]
    #[TestWith(['19:00'])]
    public function test_a_pickup_at_either_edge_of_opening_hours_is_booked(string $time): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_time', $time)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('orders', 1);
    }

    #[TestWith(['07:59'])]
    #[TestWith(['19:01'])]
    #[TestWith(['00:00'])]
    #[TestWith(['22:30'])]
    public function test_a_pickup_outside_8am_to_7pm_is_refused(string $time): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_time', $time)
            ->call('submit')
            ->assertHasErrors('pickup_time')
            ->assertSee('Pickup is from 8:00 AM to 7:00 PM.');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_the_time_field_offers_only_opening_hours(): void
    {
        Session::put('cart', [$this->variant()->id => 1]);

        Livewire::test('pages::storefront.order')
            ->assertSeeHtml('min="08:00"')
            ->assertSeeHtml('max="19:00"')
            ->assertSeeText('Between 8:00 AM and 7:00 PM.');
    }

    public function test_the_pickup_date_and_time_are_stored_as_one_moment(): void
    {
        $this->placeOrder()->assertHasNoErrors();

        $this->assertSame(
            now()->addDay()->setTime(10, 30)->toDateTimeString(),
            Order::first()->pickup_at->toDateTimeString(),
        );
    }

    public function test_it_rejects_a_pickup_date_in_the_past(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_date', now()->subDay()->toDateString())
            ->call('submit')
            ->assertHasErrors('pickup_date');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_it_rejects_a_time_already_passed_today(): void
    {
        $this->travelTo(now()->setTime(14, 0));

        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_date', now()->toDateString())
            ->set('pickup_time', '09:00')
            ->call('submit')
            ->assertHasNoErrors('pickup_date')
            ->assertHasErrors('pickup_time');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_a_later_time_today_is_accepted(): void
    {
        $this->travelTo(now()->setTime(14, 0));

        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_date', now()->toDateString())
            ->set('pickup_time', '16:00')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(now()->setTime(16, 0)->toDateTimeString(), Order::first()->pickup_at->toDateTimeString());
    }

    public function test_it_rejects_a_malformed_pickup_date_or_time(): void
    {
        $variant = $this->variant();
        Session::put('cart', [$variant->id => 1]);

        $this->withCustomerDetails(Livewire::test('pages::storefront.order'))
            ->set('pickup_date', 'tomorrow')
            ->set('pickup_time', '25:99')
            ->call('submit')
            ->assertHasErrors(['pickup_date', 'pickup_time']);

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

    public function test_the_address_still_counts_when_the_browser_limit_is_dodged(): void
    {
        $variant = $this->variant();

        // A fresh session every time is exactly what clearing cookies buys, so
        // the per-browser limit never bites. The address behind it still adds up.
        for ($i = 0; $i < 20; $i++) {
            Session::setId(Str::random(40));

            $this->placeOrder($variant)->assertHasNoErrors();
        }

        Session::setId(Str::random(40));

        $this->placeOrder($variant)->assertHasErrors('throttle');

        $this->assertSame(20, Order::count());
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
