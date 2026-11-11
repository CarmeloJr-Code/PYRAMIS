<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RestockStatus;
use App\Models\Conversation;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The screens other people change while they sit open keep themselves current:
 * orders, sales and stock, and the dashboard, restocks, production runs and
 * message list beside them.
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
            'dashboard' => ['employee.dashboard', '60s'],
            'restock list' => ['employee.restocks.index', '30s'],
            'production runs' => ['employee.production.runs.index', '30s'],
            'message list' => ['employee.messages.index', '30s'],
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
        $this->get(route('employee.restocks.show', Restock::factory()->create()))->assertOk()->assertSee('wire:poll.30s', escape: false);

        // Placed on the dashboard lazily, so it is not in the page that GET
        // returns and has to ask for itself.
        Livewire::test('bake-next')->assertSeeHtml('wire:poll.60s');
    }

    /**
     * The dashboard is the most expensive thing polled, and the panel on it
     * reads the whole lookback window, so what one poll of each costs is held
     * to the ceiling QueryBudgetTest sets for opening a screen.
     */
    public function test_a_poll_of_the_dashboard_stays_inside_the_query_budget(): void
    {
        Outlet::factory()->mainBranch()->create();

        Order::factory()->count(5)->create();
        Sale::factory()->count(5)->create();

        $manager = User::factory()->administrator()->create();

        foreach (['pages::employee.dashboard', 'bake-next'] as $component) {
            $screen = Livewire::actingAs($manager)->test($component);

            DB::flushQueryLog();
            DB::enableQueryLog();

            $screen->call('$refresh')->assertOk();

            $queries = count(DB::getQueryLog());

            DB::disableQueryLog();

            $this->assertLessThanOrEqual(25, $queries, "A poll of [{$component}] costs {$queries} queries.");
        }
    }

    public function test_the_dashboard_counts_an_order_placed_after_it_was_opened(): void
    {
        Outlet::factory()->mainBranch()->create();

        $dashboard = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.dashboard');

        $before = $dashboard->html();

        Order::factory()->create(['pickup_at' => now()->addHour()]);

        $this->assertNotSame($before, $dashboard->call('$refresh')->html());
    }

    public function test_the_restock_screens_follow_a_restock_moved_along_by_someone_else(): void
    {
        Outlet::factory()->mainBranch()->create();

        $restock = Restock::factory()->preparing()->create();
        $item = RestockItem::factory()->for($restock)->requesting(4)->create();

        $baker = User::factory()->baker()->create();

        $list = Livewire::actingAs($baker)->test('pages::employee.restocks.index');

        // Halfway through counting what was set aside.
        $page = Livewire::actingAs($baker)
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->set("prepared.{$item->id}", '3')
            ->assertSee('Save prepared quantities');

        $late = Restock::factory()->create();

        $list->assertDontSee($late->reference)->call('$refresh')->assertSee($late->reference);

        $page->call('$refresh')
            ->assertSee('Save prepared quantities')
            ->assertSet("prepared.{$item->id}", '3');

        Restock::whereKey($restock->id)->update(['status' => RestockStatus::Cancelled]);

        $page->call('$refresh')
            ->assertSet('restock.status', RestockStatus::Cancelled)
            ->assertDontSee('Save prepared quantities');
    }

    public function test_the_run_log_picks_up_a_run_logged_by_another_baker_and_keeps_the_form(): void
    {
        Outlet::factory()->mainBranch()->create();

        $log = Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.production.runs.index')
            ->set('quantity', '12')
            ->set('notes', 'Second oven');

        $run = ProductionRun::factory()->create(['produced_at' => now()]);

        $log->assertDontSee($run->reference)
            ->call('$refresh')
            ->assertSee($run->reference)
            ->assertSet('quantity', '12')
            ->assertSet('notes', 'Second oven');
    }

    public function test_the_message_list_picks_up_a_conversation_started_by_someone_else(): void
    {
        $cashier = User::factory()->cashier()->create();

        $list = Livewire::actingAs($cashier)
            ->test('pages::employee.messages.index')
            ->assertDontSee('Saturday cake orders');

        Conversation::factory()
            ->withMembers([$cashier, User::factory()->administrator()->create()])
            ->create(['subject' => 'Saturday cake orders']);

        $list->call('$refresh')->assertSee('Saturday cake orders');
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
