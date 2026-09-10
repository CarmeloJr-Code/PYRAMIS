<?php

namespace Tests\Browser;

use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * Log in → view orders → process the order → record the sale, in a real browser.
 *
 * Completing a collected pre-order is what records its sale, so the last two
 * steps of the Phase 15 cashier journey are one press: the point of walking it
 * in a browser is that the takings appear without anyone typing them twice.
 */
class CashierJourneyTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_a_cashier_signs_in_walks_an_order_to_collection_and_sees_the_takings(): void
    {
        $this->buildTheBakery();

        $cashier = $this->employee('cashier', 'Josie Cruz', 'josie@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $order = Order::factory()->create([
            'outlet_id' => $this->kiosk->id,
            'customer_name' => 'Ana Reyes',
            'status' => OrderStatus::Pending,
        ]);

        OrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => '1250.00',
        ]);

        $this->browse(function (Browser $browser) use ($cashier, $order): void {
            $browser->visit('/employee/login')
                ->type('email', $cashier->email)
                ->type('password', 'password')
                ->press('Log in')

                ->waitForLocation('/employee/dashboard')
                ->assertSee('Josie Cruz')

                ->clickLink('Orders')
                ->waitForLocation('/employee/orders')
                ->assertSee($order->reference)
                ->assertSee('Ana Reyes')
                ->waitForNavigate()->clickLink('Open')

                ->waitForText('Ube Cake')
                ->assertSee('₱2,500.00')

                // One step at a time, and never two: the button naming the next
                // step is the workflow the order enforces server-side.
                ->waitForLivewire()->press('Mark as Confirmed')
                ->assertSee('Mark as Preparing')
                ->waitForLivewire()->press('Mark as Preparing')
                ->assertSee('Mark as Ready for pickup')
                ->waitForLivewire()->press('Mark as Ready for pickup')
                ->assertSee('Mark as Completed')
                ->waitForLivewire()->press('Mark as Completed')
                ->assertSee('can no longer be changed');

            $sale = Sale::query()->latest('id')->firstOrFail();

            $browser->waitForNavigate()->clickLink('Sales')
                ->waitForLocation('/employee/sales')
                ->assertSee($sale->reference)
                ->assertSee('Mall Kiosk')
                ->assertSee('₱2,500.00');
        });

        $order->refresh();
        $sale = Sale::query()->with('items')->latest('id')->firstOrFail();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame($order->id, $sale->order_id);
        $this->assertSame(250000, $sale->totalInCentavos());
    }

    public function test_a_cashier_is_turned_away_from_the_stockroom(): void
    {
        $this->buildTheBakery();

        $cashier = $this->employee('cashier', 'Josie Cruz', 'josie@purpleyam.test');

        $this->browse(function (Browser $browser) use ($cashier): void {
            $browser->loginAs($cashier)
                ->visit('/employee/inventory')
                ->assertSee('403');
        });
    }
}
