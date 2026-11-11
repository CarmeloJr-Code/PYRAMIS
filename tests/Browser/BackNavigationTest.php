<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * Back shows the page as it is now, in a real browser.
 *
 * Only a browser can settle this one: wire:navigate answers Back from a copy
 * of the page held in memory, so nothing the server does is involved until the
 * script in resources/js/app.js asks the restored page to render again.
 */
class BackNavigationTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_going_back_to_a_list_shows_what_changed_since_it_was_left(): void
    {
        $this->buildTheBakery();

        $manager = $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $this->browse(function (Browser $browser) use ($manager, $variant): void {
            $browser->visit('/employee/login')
                ->type('email', $manager->email)
                ->type('password', 'password')
                ->press('Log in')
                ->waitForLocation('/employee/dashboard')

                ->clickLink('Products')
                ->waitForLocation('/employee/products')
                ->waitForText('Ube Cake')

                ->clickLink('Orders')
                ->waitForLocation('/employee/orders');

            // Someone else renames the cake while this screen is elsewhere.
            $variant->product->update(['name' => 'Ube Macapuno Cake']);

            $browser->back()
                ->waitForLocation('/employee/products')
                ->waitForText('Ube Macapuno Cake')
                ->assertDontSee('Ube Cake');
        });
    }
}
