<?php

namespace Tests\Feature\Employee;

use App\Actions\BuildSummaryReport;
use App\Enums\OrderStatus;
use App\Enums\SummaryPeriod;
use App\Enums\SummaryReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $main;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday mid-month, so today, this week and this month all differ.
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00'));

        $this->main = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * Every tab, with the roles its gate lets in and the ones it turns away.
     *
     * @return array<string, array{SummaryReport, list<string>, list<string>}>
     */
    public static function tabs(): array
    {
        return [
            'dashboard' => [SummaryReport::Dashboard, ['administrator', 'cashier', 'baker'], []],
            'orders' => [SummaryReport::Orders, ['administrator', 'cashier'], ['baker']],
            'sales' => [SummaryReport::Sales, ['administrator', 'cashier'], ['baker']],
            'expenses' => [SummaryReport::Expenses, ['administrator', 'cashier'], ['baker']],
            'inventory' => [SummaryReport::Inventory, ['administrator', 'baker'], ['cashier']],
            'restocking' => [SummaryReport::Restocking, ['administrator', 'baker'], ['cashier']],
            'production' => [SummaryReport::Production, ['administrator', 'baker'], ['cashier']],
            'workforce' => [SummaryReport::Workforce, ['administrator'], ['cashier', 'baker']],
            'outlets' => [SummaryReport::Outlets, ['administrator'], ['cashier', 'baker']],
            'schedule' => [SummaryReport::Schedule, ['cashier', 'baker'], ['administrator']],
        ];
    }

    /**
     * @param  list<string>  $permitted
     * @param  list<string>  $refused
     */
    #[DataProvider('tabs')]
    public function test_a_permitted_role_can_view_the_pdf(SummaryReport $report, array $permitted, array $refused): void
    {
        // One period per role, taken in turn: every period prints through the
        // same template, its dates are proven below, and each render costs
        // dompdf real memory.
        foreach ($permitted as $index => $role) {
            $period = SummaryPeriod::cases()[$index % 3];

            $response = $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('employee.summaries.show', ['report' => $report, 'period' => $period]))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');

            $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        }
    }

    /**
     * The tabs some role is turned away from — every one but the dashboard.
     *
     * @return array<string, array{SummaryReport, list<string>, list<string>}>
     */
    public static function guardedTabs(): array
    {
        return array_filter(self::tabs(), fn (array $tab): bool => $tab[2] !== []);
    }

    /**
     * @param  list<string>  $permitted
     * @param  list<string>  $refused
     */
    #[DataProvider('guardedTabs')]
    public function test_a_role_the_tab_turns_away_cannot_have_its_pdf_either(SummaryReport $report, array $permitted, array $refused): void
    {
        foreach ($refused as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('employee.summaries.show', ['report' => $report, 'period' => SummaryPeriod::Month]))
                ->assertForbidden();
        }
    }

    /**
     * @param  list<string>  $permitted
     * @param  list<string>  $refused
     */
    #[DataProvider('tabs')]
    public function test_the_tab_shows_the_button_to_the_roles_it_admits(SummaryReport $report, array $permitted, array $refused): void
    {
        $screen = match ($report) {
            SummaryReport::Dashboard => 'employee.dashboard',
            SummaryReport::Orders => 'employee.orders.index',
            SummaryReport::Sales => 'employee.sales.index',
            SummaryReport::Expenses => 'employee.expenses.index',
            SummaryReport::Inventory => 'employee.inventory.index',
            SummaryReport::Restocking => 'employee.restocks.index',
            SummaryReport::Production => 'employee.production',
            SummaryReport::Workforce => 'employee.workforce',
            SummaryReport::Outlets => 'employee.outlets.index',
            SummaryReport::Schedule => 'employee.schedule',
        };

        $this->actingAs(User::factory()->{$permitted[0]}()->create())
            ->get(route($screen))
            ->assertOk()
            ->assertSee('data-test="summary-report-button"', false)
            ->assertSee(route('employee.summaries.show', ['report' => $report, 'period' => SummaryPeriod::Today]), false)
            ->assertSee(route('employee.summaries.show', ['report' => $report, 'period' => SummaryPeriod::Week, 'download' => 1]), false);
    }

    public function test_asking_to_download_sends_an_attachment_named_for_the_tab_and_period(): void
    {
        $response = $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.summaries.show', ['report' => SummaryReport::Sales, 'period' => SummaryPeriod::Week, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(
            'attachment; filename=sales-summary-week-2026-11-16.pdf',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.summaries.show', ['report' => SummaryReport::Sales, 'period' => SummaryPeriod::Today]))
            ->assertRedirect(route('login'));
    }

    public function test_an_unknown_tab_or_period_is_not_found(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        $this->get('/employee/summaries/products/today')->assertNotFound();
        $this->get('/employee/summaries/sales/year')->assertNotFound();
    }

    public function test_each_period_covers_whole_calendar_days(): void
    {
        $this->assertSame(['2026-11-18', '2026-11-18'], [SummaryPeriod::Today->range()->from(), SummaryPeriod::Today->range()->to()]);
        $this->assertSame(['2026-11-16', '2026-11-22'], [SummaryPeriod::Week->range()->from(), SummaryPeriod::Week->range()->to()]);
        $this->assertSame(['2026-11-01', '2026-11-30'], [SummaryPeriod::Month->range()->from(), SummaryPeriod::Month->range()->to()]);
    }

    public function test_the_sales_summary_counts_only_the_period_asked_for(): void
    {
        $this->sale('2026-11-18 09:00', 2, '50.00');
        $this->sale('2026-11-16 09:00', 1, '30.00');
        $this->sale('2026-11-03 09:00', 1, '20.00');
        $this->sale('2026-10-31 09:00', 1, '999.00');

        $admin = User::factory()->administrator()->create();

        $takings = fn (SummaryPeriod $period): string => $this->metric(SummaryReport::Sales, $period, $admin, 'Takings');

        $this->assertSame('₱100.00', $takings(SummaryPeriod::Today));
        $this->assertSame('₱130.00', $takings(SummaryPeriod::Week));
        $this->assertSame('₱150.00', $takings(SummaryPeriod::Month));
    }

    public function test_the_orders_summary_lists_pickups_in_the_period_without_contact_details(): void
    {
        $today = Order::factory()->for($this->main)->status(OrderStatus::Completed)->create([
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09171234567',
            'pickup_at' => '2026-11-18 15:00',
        ]);
        OrderItem::factory()->for($today)->create(['quantity' => 2, 'unit_price' => '75.00']);

        Order::factory()->for($this->main)->create(['customer_name' => 'Next Month', 'pickup_at' => '2026-12-02 15:00']);

        $summary = app(BuildSummaryReport::class)->handle(
            SummaryReport::Orders,
            SummaryPeriod::Month->range(),
            User::factory()->cashier()->create(),
        );

        $listed = $summary['tables'][1]['rows'];

        $this->assertCount(1, $listed);
        $this->assertSame('Ana Reyes', $listed[0][1]);
        $this->assertSame('₱150.00', $listed[0][5]);
        $this->assertStringNotContainsString('09171234567', json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_schedule_summary_holds_only_the_employees_own_shifts(): void
    {
        $baker = User::factory()->baker()->create();
        $colleague = User::factory()->baker()->create();

        $mine = Shift::factory()->for($this->main)->on('2026-11-19', '06:00', '14:00')->create(['name' => 'Morning bake']);
        ShiftAssignment::factory()->for($mine)->for($baker)->create();

        $theirs = Shift::factory()->for($this->main)->on('2026-11-19', '14:00', '22:00')->create(['name' => 'Evening bake']);
        ShiftAssignment::factory()->for($theirs)->for($colleague)->create();

        $summary = app(BuildSummaryReport::class)->handle(SummaryReport::Schedule, SummaryPeriod::Week->range(), $baker);

        $this->assertSame([['label' => 'Shifts', 'value' => '1'], ['label' => 'Hours', 'value' => '8h']], $summary['metrics']);
        $this->assertSame(['Morning bake'], array_column($summary['tables'][0]['rows'], 1));
    }

    public function test_the_dashboard_summary_shows_each_role_only_its_own_sections(): void
    {
        $labels = fn (User $user): array => array_column(
            app(BuildSummaryReport::class)->handle(SummaryReport::Dashboard, SummaryPeriod::Today->range(), $user)['metrics'],
            'label',
        );

        $cashier = $labels(User::factory()->cashier()->create());
        $this->assertContains('Takings', $cashier);
        $this->assertContains('My shifts', $cashier);
        $this->assertNotContains('Units produced', $cashier);
        $this->assertNotContains('Shifts rostered', $cashier);

        $baker = $labels(User::factory()->baker()->create());
        $this->assertContains('Units produced', $baker);
        $this->assertNotContains('Takings', $baker);
        $this->assertNotContains('Expenses', $baker);

        $admin = $labels(User::factory()->administrator()->create());
        $this->assertContains('Shifts rostered', $admin);
        $this->assertNotContains('My shifts', $admin);
    }

    /**
     * A completed sale of one line at the main branch.
     */
    private function sale(string $soldAt, int $quantity, string $unitPrice): void
    {
        $sale = Sale::factory()->for($this->main)->create(['sold_at' => $soldAt]);

        SaleItem::factory()->for($sale)->create(['quantity' => $quantity, 'unit_price' => $unitPrice]);
    }

    /**
     * One headline figure from a summary, by its label.
     */
    private function metric(SummaryReport $report, SummaryPeriod $period, User $user, string $label): string
    {
        $metrics = app(BuildSummaryReport::class)->handle($report, $period->range(), $user)['metrics'];

        return collect($metrics)->firstWhere('label', $label)['value'];
    }
}
