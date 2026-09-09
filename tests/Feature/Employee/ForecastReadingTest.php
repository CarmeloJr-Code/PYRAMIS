<?php

namespace Tests\Feature\Employee;

use App\Actions\BuildForecastContext;
use App\Actions\CompactForecastContext;
use App\Actions\GenerateForecastReading;
use App\Ai\Agents\ForecastReadingAgent;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI half of forecasting.
 *
 * This is the first place in PYRAMIS that talks to anything outside itself, so
 * it is also the first test file that fakes an external service. The SDK does
 * it on the agent class — ForecastReadingAgent::fake() queues what the model
 * would have said, and the assertions read back what it was asked. No HTTP is
 * involved and no key is needed, so the suite never spends a request.
 */
class ForecastReadingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;

    protected function setUp(): void
    {
        parent::setUp();

        // The same frozen Monday DemandOutlookTest uses, so a window counted
        // back from "today" is the same window on every run.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);

        // Without a key every test would fall down the not-configured branch.
        config()->set('ai.providers.groq.key', 'test-key');
    }

    /**
     * A complete reading, in the shape the schema asks for.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reading(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'Saturdays carry the week and the ube sizes are running ahead of the ovens.',
            'confidence' => 'medium',
            'key_factors' => [
                ['factor' => 'Saturday is the busiest day', 'evidence' => '68.4 units a day against 28.0 on Tuesday'],
            ],
            'production_recommendations' => [
                [
                    'item' => 'Ube Cake Whole',
                    'action' => 'Worth considering another twenty before the weekend',
                    'reason' => 'Projected 25 against 4 in stock',
                    'priority' => 'high',
                ],
            ],
            'inventory_recommendations' => [
                [
                    'ingredient' => 'Flour',
                    'action' => 'Worth ordering before Friday',
                    'reason' => 'Three days of cover against a seven day horizon',
                    'priority' => 'high',
                ],
            ],
        ], $overrides);
    }

    /**
     * A completed sale of one line on the given day.
     */
    private function sell(string $day, int $quantity, ?ProductVariant $variant = null): void
    {
        $sale = Sale::factory()->for($this->main)->create(['sold_at' => $day.' 10:00:00']);

        $item = SaleItem::factory()->for($sale);

        if ($variant !== null) {
            $item = $item->for($variant, 'productVariant');
        }

        $item->create(['quantity' => $quantity, 'unit_price' => '100.00']);
    }

    /**
     * A size the bakery can sell.
     */
    private function variant(string $product, string $size): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size]);
    }

    /**
     * An administrator on the forecast screen.
     */
    private function screen(): Testable
    {
        return Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.forecast');
    }

    public function test_only_the_administrator_can_ask_for_a_reading(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        foreach (['baker', 'cashier'] as $role) {
            Livewire::actingAs(User::factory()->{$role}()->create())
                ->test('pages::employee.forecast')
                ->assertForbidden();
        }

        // Nobody but the Administrator reaches the screen, so nobody else can
        // spend a request against the allowance either.
        ForecastReadingAgent::assertNeverPrompted();
    }

    public function test_no_reading_is_produced_until_it_is_asked_for(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $this->screen()
            ->assertSee('Ask for a reading')
            ->assertDontSee('What stands out');

        // Loading the page costs exactly what it did before this slice.
        ForecastReadingAgent::assertNeverPrompted();
    }

    public function test_the_button_produces_a_reading_of_the_figures(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $whole = $this->variant('Ube Cake', 'Whole');
        $this->sell('2026-06-10', 20, $whole);

        $this->screen()
            ->call('generateReading')
            ->assertSee('Saturdays carry the week')
            ->assertSee('Worth considering another twenty before the weekend')
            ->assertSee('Worth ordering before Friday')
            ->assertSee('Saturday is the busiest day');

        ForecastReadingAgent::assertPromptedTimes(1);
    }

    public function test_the_brief_carries_the_sizes_that_are_short_and_says_what_it_left_out(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        // One size selling hard with nothing made, and eight that never moved.
        $short = $this->variant('Ube Cake', 'Whole');
        $this->sell('2026-06-10', 60, $short);

        for ($i = 1; $i <= 8; $i++) {
            $this->variant('Quiet Loaf '.$i, 'Slice');
        }

        $this->screen()->call('generateReading');

        ForecastReadingAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('Ube Cake Whole')
                && ! $prompt->contains('Quiet Loaf 8')
                && $prompt->contains('are covered');
        });
    }

    public function test_the_brief_does_not_call_the_overflow_covered_when_it_is_short(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        // Eleven sizes selling with nothing baked, against a table that holds
        // ten. The one pushed out is short as well, and saying otherwise would
        // be a plain untruth about the bakery's stock.
        for ($i = 1; $i <= 11; $i++) {
            $variant = $this->variant('Product '.$i, 'Size '.$i);
            $this->sell('2026-06-10', $i * 5, $variant);
        }

        $this->screen()->call('generateReading');

        ForecastReadingAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('1 further size is short as well')
                && ! $prompt->contains('are covered');
        });
    }

    public function test_the_brief_leaves_out_ingredients_that_are_covered(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        // Well above its reorder level, with nothing drawing it down.
        $sugar = Ingredient::factory()->reorderAt('0.000')->create(['name' => 'Comfortable Sugar']);

        InventoryMovement::factory()->for($sugar)->quantity('50.000')
            ->create(['occurred_at' => '2026-05-20 08:00:00']);

        $this->screen()->call('generateReading');

        ForecastReadingAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => ! $prompt->contains('Comfortable Sugar')
        );
    }

    public function test_the_brief_stays_well_inside_the_token_budget(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        // A catalogue and a stockroom on the scale of the bakery's own, every
        // size of it selling, so nothing is filtered out for being quiet.
        for ($i = 1; $i <= 11; $i++) {
            $variant = $this->variant('Product '.$i, 'Size '.$i);
            $this->sell('2026-06-10', $i * 3, $variant);
        }

        for ($i = 1; $i <= 25; $i++) {
            Ingredient::factory()->create(['name' => 'Ingredient '.$i]);
        }

        $this->screen()->call('generateReading');

        // Groq's free allowance is 8,000 tokens a minute, shared by everyone on
        // the key. At roughly four characters to a token, 4,000 characters is
        // about 1,000 tokens — leaving room for the instructions, the schema and
        // the answer, and for a second reading in the same minute.
        ForecastReadingAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => strlen($prompt->prompt) < 4000
        );
    }

    public function test_a_second_press_reads_what_is_already_held(): void
    {
        ForecastReadingAgent::fake([$this->reading(), $this->reading()]);

        $this->screen()
            ->call('generateReading')
            ->call('generateReading');

        ForecastReadingAgent::assertPromptedTimes(1);
    }

    public function test_a_different_horizon_is_read_separately(): void
    {
        ForecastReadingAgent::fake([$this->reading(), $this->reading()]);

        $this->screen()
            ->call('generateReading')
            ->set('horizonDays', 14)
            ->call('generateReading');

        ForecastReadingAgent::assertPromptedTimes(2);
    }

    public function test_reading_it_again_ignores_what_is_held(): void
    {
        ForecastReadingAgent::fake([$this->reading(), $this->reading()]);

        $this->screen()
            ->call('generateReading')
            ->call('refreshReading');

        ForecastReadingAgent::assertPromptedTimes(2);
    }

    public function test_a_reading_is_dropped_when_the_window_it_describes_changes(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $this->screen()
            ->call('generateReading')
            ->assertSee('Saturdays carry the week')
            ->set('lookbackDays', 84)
            ->assertSet('reading', null)
            ->assertDontSee('Saturdays carry the week');
    }

    public function test_the_screen_says_so_when_no_key_is_configured(): void
    {
        config()->set('ai.providers.groq.key', null);

        ForecastReadingAgent::fake([$this->reading()]);

        $this->screen()
            ->assertSee('Readings are not switched on')
            ->assertDontSee('Ask for a reading')
            ->call('generateReading')
            ->assertSet('reading', null);

        ForecastReadingAgent::assertNeverPrompted();
    }

    public function test_a_reading_that_comes_back_empty_is_not_kept(): void
    {
        ForecastReadingAgent::fake([
            $this->reading(['summary' => '   ']),
            $this->reading(),
        ]);

        $screen = $this->screen()->call('generateReading');

        $screen->assertSet('reading', null);

        // Nothing was cached, so asking again is a real retry rather than the
        // same failure served back.
        $screen->call('generateReading')->assertSee('Saturdays carry the week');

        ForecastReadingAgent::assertPromptedTimes(2);
    }

    public function test_asking_over_and_over_is_refused_before_it_reaches_the_provider(): void
    {
        ForecastReadingAgent::fake(array_fill(0, GenerateForecastReading::HOURLY_LIMIT + 2, $this->reading()));

        $screen = $this->screen();

        for ($i = 0; $i < GenerateForecastReading::HOURLY_LIMIT + 1; $i++) {
            $screen->call('refreshReading');
        }

        // The free allowance is shared, so the limit holds on our side.
        ForecastReadingAgent::assertPromptedTimes(GenerateForecastReading::HOURLY_LIMIT);
    }

    public function test_a_half_built_recommendation_is_dropped_rather_than_rendered(): void
    {
        ForecastReadingAgent::fake([$this->reading([
            'production_recommendations' => [
                ['item' => 'Ube Cake Whole', 'action' => 'Bake more'],
                [
                    'item' => 'Chocolate Cake Whole',
                    'action' => 'Worth considering ten more',
                    'reason' => 'Projected 12 against 2 in stock',
                    'priority' => 'medium',
                ],
            ],
        ])]);

        $screen = $this->screen()->call('generateReading');

        $screen->assertSee('Worth considering ten more')
            ->assertDontSee('Bake more');

        $this->assertCount(1, $screen->get('reading')['production_recommendations']);
    }

    public function test_an_unrecognised_priority_still_renders(): void
    {
        ForecastReadingAgent::fake([$this->reading([
            'confidence' => 'certain',
            'production_recommendations' => [[
                'item' => 'Ube Cake Whole',
                'action' => 'Worth considering ten more',
                'reason' => 'Projected 12 against 2 in stock',
                'priority' => 'urgent',
            ]],
        ])]);

        // A value outside the enum falls back rather than taking the page down,
        // and confidence falls back downwards.
        $this->screen()
            ->call('generateReading')
            ->assertSee('Medium')
            ->assertSee('Not very sure');
    }

    public function test_a_recommendation_naming_something_the_bakery_does_not_sell_is_marked(): void
    {
        ForecastReadingAgent::fake([$this->reading([
            'production_recommendations' => [[
                'item' => 'Mango Float Large',
                'action' => 'Worth considering ten more',
                'reason' => 'Invented out of nothing',
                'priority' => 'low',
            ]],
        ])]);

        $this->variant('Ube Cake', 'Whole');

        $this->screen()
            ->call('generateReading')
            ->assertSee('Not in the catalogue');
    }

    public function test_the_reading_shows_where_it_came_from(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        // Not a black box: the model and the moment are the page's own, never
        // the model's to claim (docs/ai-forecasting.md).
        $this->screen()
            ->call('generateReading')
            ->assertSee(ForecastReadingAgent::MODEL)
            ->assertSee('15 Jun 2026');
    }

    public function test_the_reading_says_plainly_that_it_is_not_a_prediction(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $this->screen()
            ->call('generateReading')
            ->assertSee('Written by a language model')
            ->assertSeeText('not a prediction, and not a decision')
            ->assertSeeText('no stock moved, no run was logged, no order was placed');
    }

    public function test_the_reading_writes_nothing_to_the_business_records(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $whole = $this->variant('Ube Cake', 'Whole');
        $this->sell('2026-06-10', 20, $whole);
        Ingredient::factory()->create(['name' => 'Flour']);

        $before = [
            'product_stock_movements' => ProductStockMovement::count(),
            'inventory_movements' => InventoryMovement::count(),
            'production_runs' => ProductionRun::count(),
            'sales' => Sale::count(),
        ];

        $this->screen()->call('generateReading')->assertSee('Saturdays carry the week');

        // BR-008 and BR-009: a recommendation reaches the business through a
        // person, never through this screen.
        $this->assertSame($before['product_stock_movements'], ProductStockMovement::count());
        $this->assertSame($before['inventory_movements'], InventoryMovement::count());
        $this->assertSame($before['production_runs'], ProductionRun::count());
        $this->assertSame($before['sales'], Sale::count());
    }

    public function test_the_reading_is_held_against_the_window_it_describes(): void
    {
        ForecastReadingAgent::fake([$this->reading()]);

        $this->screen()->call('generateReading');

        // The key names the window rather than what the caller asked for, so it
        // can never describe a stretch the reading does not cover. Eight weeks
        // back from the frozen Monday, projected over the default seven days.
        $this->assertTrue(Cache::has('forecast-reading:2026-04-21:2026-06-15:7'));
    }

    public function test_the_brief_leaves_the_takings_out_of_it(): void
    {
        $this->sell('2026-06-10', 20);

        $brief = app(CompactForecastContext::class)->handle(
            app(BuildForecastContext::class)->handle(28, 7),
        );

        // The one money figure in the context. A model shown revenue gives
        // revenue advice, which is neither asked for nor allowed (BR-009).
        $this->assertStringNotContainsString('takings', mb_strtolower($brief));
        $this->assertStringContainsString('DEMAND over the window', $brief);
    }
}
