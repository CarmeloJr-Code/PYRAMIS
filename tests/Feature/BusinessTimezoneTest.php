<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Today" and "now" are Malaybalay's, not UTC's.
 *
 * The cases sit between midnight and eight in the morning local time, when the
 * UTC date is still yesterday's — the stretch where a bakery bakes, and where
 * running on UTC put the morning's work on the wrong day.
 */
class BusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stop the clock at a moment given in UTC, so the test says plainly which
     * instant it means whatever zone the app runs in.
     */
    private function travelToUtc(string $moment): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, 'UTC')->setTimezone(config('app.timezone')));
    }

    /**
     * The checkout form filled in for pickup at the given local date and time.
     */
    private function orderFor(string $date, string $time): Testable
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create())
            ->create(['is_available' => true]);

        Session::put('cart', [$variant->id => 1]);

        return Livewire::test('pages::storefront.order')
            ->set('customer_name', 'Ana Reyes')
            ->set('customer_phone', '09171234567')
            ->set('outlet_id', Outlet::factory()->create()->id)
            ->set('pickup_date', $date)
            ->set('pickup_time', $time)
            ->call('submit');
    }

    public function test_at_two_in_the_morning_a_pickup_later_today_is_accepted(): void
    {
        // 02:00 on the 21st in Malaybalay; still the 20th in UTC.
        $this->travelToUtc('2026-10-20 18:00:00');

        $this->orderFor('2026-10-21', '10:00')->assertHasNoErrors();

        $this->assertSame('2026-10-21 10:00:00', Order::sole()->getRawOriginal('pickup_at'));
    }

    public function test_at_two_in_the_morning_an_hour_already_gone_is_refused(): void
    {
        $this->travelToUtc('2026-10-20 18:00:00');

        $this->orderFor('2026-10-21', '01:30')
            ->assertHasNoErrors('pickup_date')
            ->assertHasErrors('pickup_time');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_at_two_in_the_morning_yesterday_is_no_longer_a_pickup_date(): void
    {
        $this->travelToUtc('2026-10-20 18:00:00');

        $this->orderFor('2026-10-20', '23:00')->assertHasErrors('pickup_date');

        $this->assertDatabaseEmpty('orders');
    }

    public function test_a_sale_rung_up_before_eight_in_the_morning_counts_on_that_day(): void
    {
        // Last night's sale, then this morning's at 05:00 local.
        $this->travelToUtc('2026-10-20 13:00:00');
        $lastNight = Sale::factory()->create(['sold_at' => now()]);
        SaleItem::factory()->for($lastNight)->create(['quantity' => 1, 'unit_price' => '999.00']);

        $this->travelToUtc('2026-10-20 21:00:00');
        $thisMorning = Sale::factory()->create(['sold_at' => now()]);
        SaleItem::factory()->for($thisMorning)->create(['quantity' => 2, 'unit_price' => '500.00']);

        $this->assertSame('2026-10-21 05:00:00', $thisMorning->getRawOriginal('sold_at'));

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.sales.index')
            ->assertSet('date', '2026-10-21')
            ->assertSeeText('5:00am')
            ->assertSeeText('1,000.00')
            ->assertDontSeeText('999.00');

        $this->assertSame(
            ['2026-10-20', '2026-10-21'],
            Sale::dailyTakings('2026-10-20', '2026-10-21')->pluck('day')->all(),
        );
    }

    public function test_a_shift_that_ended_at_five_is_past_by_six(): void
    {
        $baker = User::factory()->baker()->create();
        $shift = Shift::factory()->for(Outlet::factory()->create())->on('2026-10-21', '09:00', '17:00')->create(['name' => 'Day bake']);
        ShiftAssignment::factory()->for($shift)->for($baker)->create();

        // 16:00 local: still on.
        $this->travelToUtc('2026-10-21 08:00:00');
        Livewire::actingAs($baker)->test('pages::employee.schedule')->assertSee('Day bake');

        // 18:00 local: over.
        $this->travelToUtc('2026-10-21 10:00:00');
        Livewire::actingAs($baker)->test('pages::employee.schedule')->assertDontSee('Day bake');
    }

    public function test_the_migration_moves_system_stamped_times_and_leaves_typed_ones(): void
    {
        $order = Order::factory()->create();
        $sale = Sale::factory()->create();
        $shift = Shift::factory()->for(Outlet::factory()->create())->create();

        // As the UTC app left them: stamped 21:00 UTC, which was 05:00 the
        // next morning in Malaybalay; the typed times already local.
        DB::table('orders')->update(['created_at' => '2026-10-20 21:00:00', 'pickup_at' => '2026-10-22 10:00:00']);
        DB::table('sales')->update(['sold_at' => '2026-10-20 21:00:00', 'voided_at' => null]);
        DB::table('shifts')->update([
            'created_at' => '2026-10-20 21:00:00',
            'starts_at' => '2026-10-21 09:00:00',
            'ends_at' => '2026-10-21 17:00:00',
        ]);

        $migration = require database_path('migrations/2026_10_20_191833_move_recorded_timestamps_to_manila_time.php');
        $migration->up();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'created_at' => '2026-10-21 05:00:00', 'pickup_at' => '2026-10-22 10:00:00']);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'sold_at' => '2026-10-21 05:00:00', 'voided_at' => null]);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'created_at' => '2026-10-21 05:00:00',
            'starts_at' => '2026-10-21 09:00:00',
            'ends_at' => '2026-10-21 17:00:00',
        ]);

        $migration->down();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'created_at' => '2026-10-20 21:00:00', 'pickup_at' => '2026-10-22 10:00:00']);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'sold_at' => '2026-10-20 21:00:00']);
    }
}
