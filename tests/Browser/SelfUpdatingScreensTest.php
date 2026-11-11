<?php

namespace Tests\Browser;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * A screen left open catches up by itself, and does not cost anyone what they
 * were typing, in a real browser.
 *
 * The second half is the one only a browser can settle: the order page polls
 * the same component its payment form lives in, and whether a poll overwrites
 * a field someone is halfway through is decided by Livewire's script, not by
 * anything the server renders.
 */
class SelfUpdatingScreensTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_an_open_order_catches_up_without_wiping_the_payment_form(): void
    {
        $this->buildTheBakery();

        $cashier = $this->employee('cashier', 'Ana Reyes', 'ana@purpleyam.test');

        $order = Order::factory()->create([
            'outlet_id' => $this->main->id,
            'customer_name' => 'Maria Clara',
        ]);

        $this->browse(function (Browser $browser) use ($cashier, $order): void {
            $browser->visit('/employee/login')
                ->type('email', $cashier->email)
                ->type('password', 'password')
                ->press('Log in')
                ->waitForLocation('/employee/dashboard')

                ->visit("/employee/orders/{$order->reference}")
                ->waitForText('Maria Clara')
                ->type('amount', '1250');

            // The customer's name is corrected from another screen while the
            // cashier is halfway through the form.
            $order->update(['customer_name' => 'Maria Clara Santos']);

            // The page asks again every fifteen seconds.
            $browser->waitForText('Maria Clara Santos', 25)
                ->assertInputValue('amount', '1250');
        });
    }
}
