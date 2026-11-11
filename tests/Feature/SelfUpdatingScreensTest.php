<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The screens about orders, sales and stock keep themselves current.
 *
 * Each is polled, the same way the notification bell and an open message
 * thread already are. What is settled here is which screens ask, how often,
 * and that the render a poll gets back is read from the database again rather
 * than from what the page opened with. That a poll leaves a half-filled form
 * alone is a browser's question, answered in tests/Browser.
 */
class SelfUpdatingScreensTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The workspace screens that poll, and how often.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workspaceScreenProvider(): array
    {
        return [
            'order queue' => ['employee.orders.index', '15s'],
            'sales log' => ['employee.sales.index', '30s'],
            'ingredient stock' => ['employee.inventory.index', '30s'],
            'finished goods' => ['employee.inventory.finished', '30s'],
            'ingredient usage' => ['employee.inventory.usage', '30s'],
        ];
    }

    #[DataProvider('workspaceScreenProvider')]
    public function test_a_workspace_screen_asks_again_on_its_own(string $route, string $interval): void
    {
        Outlet::factory()->mainBranch()->create();

        $this->actingAs(User::factory()->administrator()->create())
            ->get(route($route))
            ->assertOk()
            ->assertSee("wire:poll.{$interval}", escape: false);
    }

    public function test_the_screens_opened_on_one_record_ask_again_on_their_own(): void
    {
        Outlet::factory()->mainBranch()->create();

        $order = Order::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create();

        $this->get(route('orders.show', $order))->assertOk()->assertSee('wire:poll.15s', escape: false);
        $this->get(route('products.index'))->assertOk()->assertSee('wire:poll.60s', escape: false);
        $this->get(route('products.show', $product))->assertOk()->assertSee('wire:poll.60s', escape: false);

        $this->actingAs(User::factory()->administrator()->create());

        $this->get(route('employee.orders.show', $order))->assertOk()->assertSee('wire:poll.15s', escape: false);
        $this->get(route('employee.inventory.show', $ingredient))->assertOk()->assertSee('wire:poll.30s', escape: false);
    }

    public function test_the_order_queue_picks_up_an_order_placed_after_it_was_opened(): void
    {
        $queue = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->assertDontSee('Maria Clara');

        Order::factory()->create(['customer_name' => 'Maria Clara']);

        $queue->call('$refresh')->assertSee('Maria Clara');
    }

    public function test_an_open_order_follows_a_status_changed_elsewhere(): void
    {
        $order = Order::factory()->create();

        $page = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->set('amount', '500')
            ->assertSee('Amount received');

        Order::whereKey($order->id)->update(['status' => OrderStatus::Cancelled]);

        $page->call('$refresh')
            ->assertSee('can no longer be changed')
            ->assertDontSee('Amount received')
            ->assertSet('amount', '500');
    }

    public function test_the_customer_sees_the_bakery_move_their_order_along(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $page = Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->assertSee(OrderStatus::Confirmed->label());

        Order::whereKey($order->id)->update(['status' => OrderStatus::Ready]);

        $page->call('$refresh')->assertSet('order.status', OrderStatus::Ready);
    }

    public function test_the_sales_log_picks_up_a_sale_rung_up_at_another_till(): void
    {
        $log = Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.index');

        $sale = Sale::factory()->create();

        $log->assertDontSee($sale->reference)
            ->call('$refresh')
            ->assertSee($sale->reference);
    }

    public function test_ingredient_stock_follows_a_movement_recorded_by_someone_else(): void
    {
        $flour = Ingredient::factory()->create(['name' => 'All-Purpose Flour']);

        InventoryMovement::factory()->for($flour)->quantity('5.000')->create();

        $baker = User::factory()->baker()->create();

        $list = Livewire::actingAs($baker)->test('pages::employee.inventory.index');
        $ledger = Livewire::actingAs($baker)->test('pages::employee.inventory.show', ['ingredient' => $flour]);

        InventoryMovement::factory()->for($flour)->quantity('37.500')->create();

        $list->call('$refresh')->assertSee('42.5');
        $ledger->call('$refresh')->assertSee('42.5');
    }
}
