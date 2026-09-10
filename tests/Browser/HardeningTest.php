<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * The hardening claim only a real browser can settle.
 *
 * A layout is responsive if a viewport says so and not otherwise: a Blade file
 * full of `sm:` prefixes proves nothing about what the browser actually draws,
 * and the failure it hides — a page that scrolls sideways on a phone — is
 * invisible on the desktop it was written on.
 *
 * The forged-post check lives in CsrfProtectionTest instead. A browser cannot
 * settle that one: stripping the token from the markup only lasts until the
 * page's own scripts put it back.
 */
class HardeningTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    /**
     * A phone, roughly. Narrower than any breakpoint the design uses.
     */
    private const PHONE = [375, 812];

    public function test_no_screen_scrolls_sideways_on_a_phone(): void
    {
        $this->buildTheBakery();

        $manager = $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $this->recipeFor($variant, 6, '2', '20');

        $screens = [
            '/',
            '/products',
            '/employee/dashboard',
            '/employee/orders',
            '/employee/sales',
            '/employee/sales/create',
            '/employee/expenses',
            '/employee/inventory',
            '/employee/inventory/finished',
            '/employee/inventory/usage',
            '/employee/production',
            '/employee/production/runs',
            '/employee/production/recipes',
            '/employee/restocks',
            '/employee/restocks/create',
            '/employee/outlets',
            '/employee/products',
            '/employee/workforce',
            '/employee/workforce/employees',
            '/employee/reports',
            '/employee/reports/sales',
            '/employee/forecast',
            '/employee/messages',
            '/employee/schedule',
        ];

        $this->browse(function (Browser $browser) use ($manager, $screens): void {
            $browser->loginAs($manager)->resize(...self::PHONE);

            foreach ($screens as $screen) {
                $browser->visit($screen);

                // A little slack for the scrollbar the browser draws itself.
                $overflow = $browser->driver->executeScript(
                    'return document.documentElement.scrollWidth - document.documentElement.clientWidth'
                );

                $this->assertLessThanOrEqual(
                    2,
                    $overflow,
                    "[{$screen}] is {$overflow}px wider than a phone, so it scrolls sideways.",
                );
            }
        });
    }
}
