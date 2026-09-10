<?php

namespace Tests\Browser;

use App\Enums\RestockStatus;
use App\Models\Restock;
use App\Models\Shift;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * Log in → dashboard → workforce → restock → reports → forecast, in a real
 * browser.
 *
 * The manager's round of the workspace, which is where the reach of the
 * Administrator role shows: every screen the capability matrix gives them opens,
 * and the forecast at the end of it says in as many words that it is not a
 * prediction (BR-008).
 */
class AdministratorJourneyTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_a_manager_signs_in_and_walks_the_whole_workspace(): void
    {
        $this->buildTheBakery();

        $manager = $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test');
        $baker = $this->employee('baker', 'Ramon Diaz', 'ramon@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        $shift = Shift::factory()->create([
            'name' => 'Morning bake',
            'outlet_id' => $this->main->id,
            'created_by' => $manager->id,
        ]);

        $shift->employees()->attach($baker->id, ['assigned_by' => $manager->id]);

        $scheduledFor = now()->addDays(2)->format('Y-m-d');

        $this->browse(function (Browser $browser) use ($manager, $variant, $scheduledFor): void {
            $browser->visit('/employee/login')
                ->type('email', $manager->email)
                ->type('password', 'password')
                ->press('Log in')

                ->waitForLocation('/employee/dashboard')
                ->assertSee('Welcome, Lita Santos')

                ->clickLink('Workforce')
                ->waitForLocation('/employee/workforce')
                ->assertSee('Morning bake')
                ->assertSee('Ramon Diaz')

                ->waitForNavigate()->clickLink('Restocking')
                ->waitForLocation('/employee/restocks')
                ->waitForNavigate()->clickLink('Schedule restock')
                ->waitForLocation('/employee/restocks/create')

                ->select('outlet_id', (string) $this->kiosk->id)
                ->fillDateField('scheduled_for', $scheduledFor)
                ->select('lines.0.product_variant_id', (string) $variant->id)
                ->type('lines.0.quantity', '6')
                ->waitForLivewire()->press('Schedule restock')
                ->waitForText('Requested');

            $restock = Restock::query()->latest('id')->firstOrFail();

            $browser->assertSee($restock->reference)

                ->waitForNavigate()->clickLink('Reports')
                ->waitForLocation('/employee/reports')
                ->assertSee('Sales')
                ->assertSee('Workforce')

                ->waitForNavigate()->click('a[href$="/employee/reports/sales"]')
                ->waitForLocation('/employee/reports/sales')
                ->assertSee('Takings')

                ->waitForNavigate()->clickLink('Forecast')
                ->waitForLocation('/employee/forecast')
                ->waitForText('Demand outlook')

                // The line the whole screen hangs on: decision support, never
                // an instruction and never a guarantee.
                ->assertSee('An estimate, not a prediction')
                ->assertSee('Ube Cake');
        });

        $restock = Restock::query()->with('items')->latest('id')->firstOrFail();

        $this->assertSame(RestockStatus::Requested, $restock->status);
        $this->assertSame($this->kiosk->id, $restock->outlet_id);
        $this->assertSame($manager->id, $restock->requested_by);
        $this->assertSame($scheduledFor, $restock->scheduled_for->toDateString());
        $this->assertSame(6, $restock->requestedUnits());
    }

    public function test_a_manager_reaches_every_screen_the_other_roles_cannot(): void
    {
        $this->buildTheBakery();

        $manager = $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test');

        $this->browse(function (Browser $browser) use ($manager): void {
            $browser->loginAs($manager);

            foreach (['/employee/workforce', '/employee/forecast', '/employee/products', '/employee/outlets'] as $screen) {
                $browser->visit($screen)->assertDontSee('403');
            }
        });
    }
}
