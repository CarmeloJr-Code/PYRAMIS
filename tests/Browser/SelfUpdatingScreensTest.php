<?php

namespace Tests\Browser;

use App\Models\Order;
use App\Models\ProductionRun;
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

    /**
     * One sign-in for both screens: the browser keeps its session between
     * tests, so a second test would find itself already past the login form.
     */
    public function test_open_screens_catch_up_without_wiping_what_is_being_typed(): void
    {
        $this->buildTheBakery();

        $manager = $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $this->recipeFor($variant, 6, '2', '20');

        $order = Order::factory()->create([
            'outlet_id' => $this->main->id,
            'customer_name' => 'Maria Clara',
        ]);

        $this->browse(function (Browser $browser) use ($manager, $order, $variant): void {
            $browser->visit('/employee/login')
                ->type('email', $manager->email)
                ->type('password', 'password')
                ->press('Log in')
                ->waitForLocation('/employee/dashboard')

                ->visit("/employee/orders/{$order->reference}")
                ->waitForText('Maria Clara')
                ->type('amount', '1250');

            // The customer's name is corrected from another screen while the
            // payment form is half filled in.
            $order->update(['customer_name' => 'Maria Clara Santos']);

            // The order page asks again every fifteen seconds.
            $browser->waitForText('Maria Clara Santos', 25)
                ->assertInputValue('amount', '1250')

                ->visit('/employee/production/runs')
                ->waitForText('Log a run')
                ->select('product_variant_id', (string) $variant->id)
                ->waitForText('This run will use')
                ->type('quantity', '12')
                ->type('notes', 'Second oven');

            // Another baker logs a run while this one is still being entered.
            $run = ProductionRun::factory()->create([
                'product_variant_id' => $variant->id,
                'produced_at' => now(),
            ]);

            // The run log asks again every thirty seconds.
            $browser->waitForText($run->reference, 45)
                ->assertSelected('product_variant_id', (string) $variant->id)
                ->assertInputValue('quantity', '12')
                ->assertInputValue('notes', 'Second oven');
        });
    }
}
