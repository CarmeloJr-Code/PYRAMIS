<?php

namespace Tests\Unit;

use App\Concerns\ReportRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A Sunday in the middle of a month, so a week preset and a month
        // preset cannot accidentally agree.
        CarbonImmutable::setTestNow('2026-03-15 14:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_reads_the_two_dates_as_they_were_typed(): void
    {
        $range = ReportRange::between('2026-03-01', '2026-03-10');

        $this->assertSame('2026-03-01', $range->from());
        $this->assertSame('2026-03-10', $range->to());
    }

    public function test_a_range_entered_back_to_front_is_put_in_order(): void
    {
        $range = ReportRange::between('2026-03-10', '2026-03-01');

        $this->assertSame('2026-03-01', $range->from());
        $this->assertSame('2026-03-10', $range->to());
    }

    public function test_an_unparseable_date_falls_back_rather_than_reaching_the_database(): void
    {
        $range = ReportRange::between("' OR 1=1 --", 'not a date');

        $this->assertSame('2026-03-01', $range->from());
        $this->assertSame('2026-03-15', $range->to());
    }

    public function test_a_missing_date_falls_back_the_same_way(): void
    {
        $range = ReportRange::between('', '');

        $this->assertSame('2026-03-01', $range->from());
        $this->assertSame('2026-03-15', $range->to());
    }

    public function test_the_span_counts_both_ends(): void
    {
        $this->assertSame(1, ReportRange::between('2026-03-10', '2026-03-10')->days());
        $this->assertSame(10, ReportRange::between('2026-03-01', '2026-03-10')->days());
    }

    public function test_a_report_opens_on_the_month_so_far(): void
    {
        $this->assertSame(['2026-03-01', '2026-03-15'], ReportRange::currentMonth());
    }

    public function test_each_preset_a_manager_asks_for_by_name(): void
    {
        $this->assertSame(['2026-03-15', '2026-03-15'], ReportRange::preset('today'));
        $this->assertSame(['2026-03-09', '2026-03-15'], ReportRange::preset('week'));
        $this->assertSame(['2026-03-01', '2026-03-15'], ReportRange::preset('month'));
        $this->assertSame(['2025-12-16', '2026-03-15'], ReportRange::preset('quarter'));
    }

    public function test_a_preset_nobody_offers_falls_back_to_the_month(): void
    {
        $this->assertSame(ReportRange::preset('month'), ReportRange::preset('decade'));
    }

    public function test_the_comparison_period_is_the_same_length_immediately_before(): void
    {
        $range = ReportRange::between('2026-03-08', '2026-03-14');

        $this->assertSame(7, $range->days());
        $this->assertSame('2026-03-01', $range->previousFrom());
        $this->assertSame('2026-03-07', $range->previousTo());
    }

    public function test_a_single_day_is_compared_with_the_day_before(): void
    {
        $range = ReportRange::between('2026-03-10', '2026-03-10');

        $this->assertSame('2026-03-09', $range->previousFrom());
        $this->assertSame('2026-03-09', $range->previousTo());
    }

    public function test_it_reports_which_way_a_figure_moved(): void
    {
        $range = ReportRange::between('2026-03-01', '2026-03-10');

        $this->assertSame(50.0, $range->change(150, 100));
        $this->assertSame(-25.0, $range->change(75, 100));
        $this->assertSame(0.0, $range->change(100, 100));
    }

    public function test_growth_from_nothing_is_not_a_percentage(): void
    {
        $range = ReportRange::between('2026-03-01', '2026-03-10');

        $this->assertNull($range->change(500, 0));
        $this->assertNull($range->change(0, 0));
    }

    public function test_a_change_is_rounded_to_one_decimal_place(): void
    {
        $range = ReportRange::between('2026-03-01', '2026-03-10');

        $this->assertSame(33.3, $range->change(400, 300));
    }

    public function test_the_range_reads_as_a_heading(): void
    {
        $this->assertSame(
            '10 Mar 2026',
            ReportRange::between('2026-03-10', '2026-03-10')->label(),
        );

        $this->assertSame(
            '01 Mar 2026 – 10 Mar 2026',
            ReportRange::between('2026-03-01', '2026-03-10')->label(),
        );
    }
}
