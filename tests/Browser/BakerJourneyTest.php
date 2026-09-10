<?php

namespace Tests\Browser;

use App\Enums\RestockStatus;
use App\Models\ProductionRun;
use App\Models\Restock;
use App\Models\RestockItem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\BuildsTheBakery;
use Tests\DuskTestCase;

/**
 * Log in → view production → record a bake → set a restock aside, in a real
 * browser.
 *
 * The two halves are one story: what the oven produces at the main branch is
 * what the outlet's restock can draw on, and an outlet never bakes its own
 * (BR-003, BR-004).
 */
class BakerJourneyTest extends DuskTestCase
{
    use BuildsTheBakery, DatabaseTruncation;

    public function test_a_baker_signs_in_logs_a_bake_and_sets_a_restock_aside(): void
    {
        $this->buildTheBakery();

        $baker = $this->employee('baker', 'Ramon Diaz', 'ramon@purpleyam.test');
        $variant = $this->cake('Ube Cake', 'Large (10x14)', '1250.00');

        // One batch of six, calling for two kilos, against twenty in the
        // stockroom: enough for the run the journey logs and no guesswork about
        // whether it will be refused.
        $ingredient = $this->recipeFor($variant, 6, '2', '20');

        $restock = Restock::factory()->create([
            'outlet_id' => $this->kiosk->id,
            'status' => RestockStatus::Requested,
            'requested_by' => $this->employee('administrator', 'Lita Santos', 'lita@purpleyam.test')->id,
        ]);

        $item = RestockItem::factory()->for($restock)->requesting(4)->create([
            'product_variant_id' => $variant->id,
        ]);

        $this->browse(function (Browser $browser) use ($baker, $restock, $item): void {
            $browser->visit('/employee/login')
                ->type('email', $baker->email)
                ->type('password', 'password')
                ->press('Log in')

                ->waitForLocation('/employee/dashboard')
                ->assertSee('Ramon Diaz')

                ->clickLink('Production')
                ->waitForLocation('/employee/production')
                ->assertSee('Recipes')

                // By address rather than by text: all three cards on the hub
                // open with a link that says "Open".
                ->waitForNavigate()->click('a[href$="/employee/production/runs"]')
                ->waitForLocation('/employee/production/runs')
                ->waitForText('Log a run')

                ->select('product_variant_id', (string) $item->product_variant_id)
                ->waitForText('This run will use')
                ->type('quantity', '12')
                ->waitForLivewire()->press('Log run')

                // Two batches of six, so the stockroom gives up four of its
                // twenty kilos and says so on the same screen.
                ->waitForText('Ube Halaya')
                ->assertSee('12')

                ->waitForNavigate()->clickLink('Restocking')
                ->waitForLocation('/employee/restocks')
                ->assertSee($restock->reference)
                ->waitForNavigate()->clickLink('Open')

                ->waitForText('Nothing has been set aside yet')
                ->waitForLivewire()->press('Start preparing')

                ->waitForText('Set the quantity actually put aside')
                ->type('prepared.'.$item->id, '4')
                ->waitForLivewire()->press('Save prepared quantities')
                ->waitForText('Deliver to outlet');
        });

        $run = ProductionRun::query()->latest('id')->firstOrFail();

        $this->assertSame(12, $run->quantity);
        $this->assertSame($baker->id, $run->recorded_by);

        // Four kilos out of twenty for two batches, from the ledger rather than
        // from a field anyone overwrote.
        $this->assertSame(16000, $ingredient->fresh()->stockInThousandths());

        $restock->refresh();

        $this->assertSame(RestockStatus::Preparing, $restock->status);
        $this->assertSame(4, $restock->preparedUnits());
        $this->assertSame($baker->id, $restock->prepared_by);
    }
}
