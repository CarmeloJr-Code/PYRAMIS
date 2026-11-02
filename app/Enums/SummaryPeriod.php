<?php

namespace App\Enums;

use App\Concerns\ReportRange;
use Carbon\CarbonImmutable;

/**
 * The stretch a tab's summary PDF covers.
 *
 * Whole calendar periods rather than "so far": for the history tabs the days
 * still to come are simply empty, while the forward-looking ones — pickups,
 * restocks, a roster — need the rest of the week to be worth printing.
 */
enum SummaryPeriod: string
{
    case Today = 'today';

    case Week = 'week';

    case Month = 'month';

    /**
     * The name a menu shows it by.
     */
    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Week => 'This week',
            self::Month => 'This month',
        };
    }

    /**
     * The days it covers, both ends included.
     */
    public function range(): ReportRange
    {
        $now = CarbonImmutable::now();

        [$start, $end] = match ($this) {
            self::Today => [$now, $now],
            self::Week => [$now->startOfWeek(), $now->endOfWeek()],
            self::Month => [$now->startOfMonth(), $now->endOfMonth()],
        };

        return ReportRange::between($start->toDateString(), $end->toDateString());
    }
}
