<?php

namespace Tests\Feature\Storefront;

use App\Actions\CountAvailableStock;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MenuStockTest extends TestCase
{
    use RefreshDatabase;

    protected Outlet $main;

    protected Outlet $plaza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->plaza = Outlet::factory()->create(['name' => 'Plaza Outlet']);
    }

    /**
     * One size of one active product.
     */
    protected function cake(string $product = 'Chocolate Cake', string $size = 'Round', bool $available = true): ProductVariant
    {
        $factory = ProductVariant::factory()->for(Product::factory()->create([
            'name' => $product,
            'slug' => str($product)->slug()->value(),
        ]));

        return ($available ? $factory : $factory->unavailable())->create(['name' => $size]);
    }

    /**
     * Put units on (or, negative, take them off) a shelf.
     */
    protected function shelve(ProductVariant $variant, Outlet $outlet, int $units): void
    {
        ProductStockMovement::factory()->for($variant)->for($outlet)->quantity($units)->create();
    }

    /**
     * An order for pickup at the outlet, in the given state — due today
     * unless a pickup moment is given.
     */
    protected function order(ProductVariant $variant, Outlet $outlet, int $quantity, OrderStatus $status = OrderStatus::Pending, ?CarbonInterface $pickupAt = null): void
    {
        OrderItem::factory()
            ->for(Order::factory()->for($outlet)->status($status)->state(['pickup_at' => $pickupAt ?? today()->setTime(17, 0)]))
            ->for($variant)
            ->create(['quantity' => $quantity]);
    }

    /**
     * The product page for one size, at ten in the morning so a pickup today
     * is still open whenever the suite runs.
     */
    protected function productPage(ProductVariant $variant): Testable
    {
        $this->travelTo(today()->setTime(10, 0));

        return Livewire::withQueryParams(['size' => $variant->id])
            ->test('pages::storefront.product', ['product' => $variant->product]);
    }

    public function test_each_size_shows_how_many_are_on_the_shelf_at_every_pickup_outlet(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 12);
        $this->shelve($round, $this->plaza, 3);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Chocolate Cake', 'Round', 'Main Branch', '12 available today', 'Plaza Outlet', '3 available today']);
    }

    public function test_open_orders_due_today_are_taken_off_the_outlet_they_will_be_collected_from(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 12);
        $this->shelve($round, $this->plaza, 5);

        $this->order($round, $this->plaza, 2, OrderStatus::Pending);
        $this->order($round, $this->plaza, 1, OrderStatus::Ready);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '12 available today', 'Plaza Outlet', '2 available today']);
    }

    public function test_a_pre_order_for_a_later_day_does_not_count_against_today(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 20);

        $this->order($round, $this->main, 4, pickupAt: today()->addDay()->setTime(9, 0));
        $this->order($round, $this->main, 3, pickupAt: today()->addWeek()->setTime(9, 0));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '20 available today']);
    }

    public function test_on_its_pickup_day_a_pre_order_takes_back_the_surplus_baked_for_it(): void
    {
        // Twenty baked for the counter and four on top for today's pre-orders.
        $round = $this->cake();
        $this->shelve($round, $this->main, 24);
        $this->order($round, $this->main, 4, OrderStatus::Confirmed);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '20 available today']);
    }

    public function test_an_order_left_uncollected_from_a_past_day_still_holds_its_goods(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 5);
        $this->order($round, $this->main, 2, OrderStatus::Ready, today()->subDay()->setTime(15, 0));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '3 available today']);
    }

    public function test_finished_orders_no_longer_claim_anything(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 4);

        $this->order($round, $this->main, 3, OrderStatus::Completed);
        $this->order($round, $this->main, 3, OrderStatus::Cancelled);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '4 available today']);
    }

    public function test_an_empty_or_overdrawn_shelf_is_out_of_stock_today_but_open_for_pre_order(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 2);
        $this->order($round, $this->main, 5);
        $this->shelve($round, $this->plaza, -3);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', 'Out of stock today', 'Plaza Outlet', 'Out of stock today'])
            ->assertDontSeeText('-3')
            ->assertDontSeeText('Sold out');

        $page = $this->productPage($round)
            ->assertSeeText('Out of stock today')
            ->assertDontSeeText('Buy now')
            ->call('addToOrder', 'today')
            ->assertNoRedirect();

        $this->assertNull(session('cart'));

        // Zero on every shelf is still a size the bakery bakes to order.
        $page->call('addToOrder', 'later')->assertRedirect(route('order'));

        $this->assertSame([$round->id => 1], session('cart'));
        $this->assertSame('later', session('cart_pickup'));
    }

    public function test_buy_now_puts_the_size_in_the_cart_for_pickup_today(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->plaza, 1);

        $this->productPage($round)
            ->assertSeeText('Buy now')
            ->call('addToOrder', 'today')
            ->assertRedirect(route('order'));

        $this->assertSame([$round->id => 1], session('cart'));
        $this->assertSame('today', session('cart_pickup'));
    }

    public function test_the_button_pressed_last_sets_the_pickup_for_the_whole_cart(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 5);

        $this->productPage($round)
            ->call('addToOrder', 'today')
            ->call('addToOrder', 'later');

        $this->assertSame([$round->id => 2], session('cart'));
        $this->assertSame('later', session('cart_pickup'));
    }

    public function test_after_the_last_pickup_hour_buy_now_waits_for_tomorrow(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 5);

        $page = $this->productPage($round);
        $this->travelTo(today()->setTime(19, 30));

        $page->call('addToOrder', 'today')->assertNoRedirect();

        $this->assertNull(session('cart'));

        $this->get(route('products.show', ['product' => $round->product, 'size' => $round->id]))
            ->assertOk()
            ->assertSeeText('No more pickups today')
            ->assertSeeText('5 available today');
    }

    public function test_a_pickup_choice_that_is_neither_today_nor_later_is_ignored(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 5);

        $this->productPage($round)
            ->call('addToOrder', 'yesterday')
            ->assertNoRedirect();

        $this->assertNull(session('cart'));
        $this->assertNull(session('cart_pickup'));
    }

    public function test_a_sold_out_size_shows_no_counts(): void
    {
        $tincan = $this->cake(size: 'Tincan', available: false);
        $this->shelve($tincan, $this->main, 9);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeText('Sold out')
            ->assertDontSeeText('9 available')
            ->assertDontSeeText('Main Branch');

        $this->get(route('products.show', ['product' => $tincan->product, 'size' => $tincan->id]))
            ->assertOk()
            ->assertSeeText('Sold out')
            ->assertDontSeeText('9 available')
            ->assertDontSeeText('Main Branch');
    }

    public function test_the_product_page_shows_the_same_counts_as_its_card(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 12);
        $this->shelve($round, $this->plaza, 5);
        $this->order($round, $this->plaza, 5);

        // Before the last pickup hour, or Buy now reads "No more pickups today".
        $this->travelTo(today()->setTime(10, 0));

        $this->get(route('products.show', ['product' => $round->product, 'size' => $round->id]))
            ->assertOk()
            ->assertSeeInOrder(['Round', 'Main Branch', '12 available today', 'Plaza Outlet', 'Out of stock today', 'Buy now', 'Pre-order']);
    }

    public function test_an_outlet_that_is_closed_is_not_listed(): void
    {
        $closed = Outlet::factory()->inactive()->create(['name' => 'Closed Kiosk']);
        $round = $this->cake();
        $this->shelve($round, $closed, 6);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeText('Main Branch')
            ->assertDontSeeText('Closed Kiosk')
            ->assertDontSeeText('6 available');
    }

    public function test_the_count_is_kept_apart_per_size(): void
    {
        $product = Product::factory()->create(['name' => 'Chocolate Cake', 'slug' => 'chocolate-cake']);
        $small = ProductVariant::factory()->for($product)->create(['name' => 'Small']);
        $large = ProductVariant::factory()->for($product)->create(['name' => 'Large']);

        $this->shelve($small, $this->main, 7);
        $this->shelve($large, $this->main, 1);
        $this->order($large, $this->main, 1);

        $this->assertSame(
            [
                $small->id => [$this->main->id => 7, $this->plaza->id => 0],
                $large->id => [$this->main->id => 0, $this->plaza->id => 0],
            ],
            app(CountAvailableStock::class)->handle(collect([$small->id, $large->id]), collect([$this->main->id, $this->plaza->id])),
        );
    }

    public function test_counting_writes_nothing(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 3);
        $this->order($round, $this->main, 1);

        $this->get(route('products.index'))->assertOk();

        $this->assertDatabaseCount('product_stock_movements', 1);
        $this->assertDatabaseHas('orders', ['status' => OrderStatus::Pending->value]);
        $this->assertSame([], app(CountAvailableStock::class)->handle(collect(), collect([$this->main->id])));
    }
}
