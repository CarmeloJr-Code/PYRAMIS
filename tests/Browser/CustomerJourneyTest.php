<?php

namespace Tests\Browser;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * Browse → select → order → submit → track, in a real browser.
 *
 * The feature tests already prove each step against the component. What only a
 * browser can show is that the steps join up for someone holding no account and
 * clicking through the storefront the way a customer actually does (BR-001).
 */
class CustomerJourneyTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_a_customer_browses_orders_and_tracks_it_without_an_account(): void
    {
        $this->buildTheBakery();

        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $pickup = now()->addDay()->format('Y-m-d\TH:i');

        $this->browse(function (Browser $browser) use ($pickup): void {
            $browser->visit('/')
                ->assertSee('Ube cakes and pastries, baked in Malaybalay.')
                ->clickLink('See the menu')

                ->waitForText('The menu')
                ->assertSee('Ube Cake')
                ->clickLink('Ube Cake')

                ->waitForText('Sizes and prices')
                ->assertSee('Large (10x14)')
                ->press('Add to order')

                // Waited for by heading rather than by "Your order", which is
                // also the name of a link standing in the header of every page.
                ->waitForLocation('/order')
                ->waitForText('Your details')
                ->assertSee('Ube Cake')
                ->assertSee('₱1,250.00')

                ->type('customer_name', 'Ana Reyes')
                ->type('customer_phone', '09171234567')
                ->select('outlet_id', (string) $this->kiosk->id)
                ->fillDateField('pickup_at', $pickup)
                ->press('Place pre-order')

                // The reference is the only thing a customer holds, so the
                // journey is not done until the tracking page shows it. Waited
                // for by a sentence rather than a label: the labels on this
                // page are upper-cased in CSS, and the browser reports what it
                // drew rather than what the markup says.
                ->waitForText('Keep this reference')
                ->assertSee('Pending')
                ->assertSee('Mall Kiosk');

            $order = Order::query()->latest('id')->firstOrFail();

            $browser->assertSee($order->reference)
                ->assertPathIs('/orders/'.$order->reference);
        });

        $order = Order::query()->with('items')->latest('id')->firstOrFail();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame($this->kiosk->id, $order->outlet_id);
        $this->assertSame('1250.00', $order->total());
        $this->assertSame($variant->id, $order->items->first()->product_variant_id);
    }

    public function test_a_reference_nobody_issued_is_not_a_page(): void
    {
        $this->buildTheBakery();

        $this->browse(function (Browser $browser): void {
            $browser->visit('/orders/PYM-NOTAREAL')
                ->assertSee('404');
        });
    }
}
