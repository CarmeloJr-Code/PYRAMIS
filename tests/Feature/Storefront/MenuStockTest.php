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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * A pre-order for pickup at the outlet, in the given state.
     */
    protected function order(ProductVariant $variant, Outlet $outlet, int $quantity, OrderStatus $status = OrderStatus::Pending): void
    {
        OrderItem::factory()
            ->for(Order::factory()->for($outlet)->status($status))
            ->for($variant)
            ->create(['quantity' => $quantity]);
    }

    public function test_each_size_shows_how_many_are_on_the_shelf_at_every_pickup_outlet(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 12);
        $this->shelve($round, $this->plaza, 3);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Chocolate Cake', 'Round', 'Main Branch', '12 available', 'Plaza Outlet', '3 available']);
    }

    public function test_open_pre_orders_are_taken_off_the_outlet_they_will_be_collected_from(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 12);
        $this->shelve($round, $this->plaza, 5);

        $this->order($round, $this->plaza, 2, OrderStatus::Pending);
        $this->order($round, $this->plaza, 1, OrderStatus::Ready);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '12 available', 'Plaza Outlet', '2 available']);
    }

    public function test_finished_orders_no_longer_claim_anything(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 4);

        $this->order($round, $this->main, 3, OrderStatus::Completed);
        $this->order($round, $this->main, 3, OrderStatus::Cancelled);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '4 available']);
    }

    public function test_an_empty_or_overdrawn_shelf_shows_zero_and_stays_open_for_pre_order(): void
    {
        $round = $this->cake();
        $this->shelve($round, $this->main, 2);
        $this->order($round, $this->main, 5);
        $this->shelve($round, $this->plaza, -3);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder(['Main Branch', '0 available — pre-order', 'Plaza Outlet', '0 available — pre-order'])
            ->assertDontSeeText('-3')
            ->assertDontSeeText('Sold out');

        // Zero on every shelf is still a size the bakery takes orders for.
        Livewire::test('pages::storefront.product', ['product' => $round->product])
            ->call('addToOrder')
            ->assertRedirect(route('order'));

        $this->assertSame([$round->id => 1], session('cart'));
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
