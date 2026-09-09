<?php

namespace App\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The stretch of days a report covers.
 *
 * Every report screen asks the same question of a different table, so the
 * dates, the presets and the preceding period for comparison are worked out
 * here once rather than six times.
 *
 * A value object rather than a trait: the report screens are Livewire
 * single-file components, which static analysis cannot see, and a trait used
 * only from there can never be proven used.
 */
final class ReportRange
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * A range from two dates as they were typed, read in order.
     *
     * Ordered rather than taken literally: a range entered back to front is a
     * slip, and returning nothing for it would look like a business with no
     * sales.
     */
    public static function between(string $from, string $to): self
    {
        $first = self::parse($from, CarbonImmutable::now()->startOfMonth());
        $second = self::parse($to, CarbonImmutable::now());

        return new self($first->min($second), $first->max($second));
    }

    /**
     * The two dates behind one of the stretches a manager asks for by name.
     *
     * @return array{0: string, 1: string}
     */
    public static function preset(string $preset): array
    {
        $start = match ($preset) {
            'today' => CarbonImmutable::now()->startOfDay(),
            'week' => CarbonImmutable::now()->startOfWeek(),
            'quarter' => CarbonImmutable::now()->subDays(89),
            default => CarbonImmutable::now()->startOfMonth(),
        };

        return [$start->toDateString(), CarbonImmutable::now()->toDateString()];
    }

    /**
     * The dates a report opens on, before anyone has chosen any.
     *
     * @return array{0: string, 1: string}
     */
    public static function currentMonth(): array
    {
        return self::preset('month');
    }

    /**
     * The first day covered.
     */
    public function from(): string
    {
        return $this->start->toDateString();
    }

    /**
     * The last day covered, inclusive.
     */
    public function to(): string
    {
        return $this->end->toDateString();
    }

    /**
     * How many days the range spans, counting both ends.
     */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /**
     * The first day of the stretch of equal length immediately before this
     * one, which is what "up on last month" is measured against.
     */
    public function previousFrom(): string
    {
        return $this->start->subDays($this->days())->toDateString();
    }

    /**
     * The last day of that preceding stretch.
     */
    public function previousTo(): string
    {
        return $this->start->subDay()->toDateString();
    }

    /**
     * Which way a figure moved against the preceding stretch, as a percentage.
     *
     * Lives on the range because the range is what decides which stretch is
     * being compared with which. Null when there is nothing to compare
     * against: growth from zero is not a percentage, and showing one would
     * invent a number.
     */
    public function change(int $now, int $before): ?float
    {
        if ($before === 0) {
            return null;
        }

        return round((($now - $before) / $before) * 100, 1);
    }

    /**
     * The range in words, for a heading.
     */
    public function label(): string
    {
        if ($this->start->isSameDay($this->end)) {
            return $this->start->format('d M Y');
        }

        return $this->start->format('d M Y').' – '.$this->end->format('d M Y');
    }

    /**
     * Parse a date bound to the query string, falling back when it is not one.
     *
     * The dates arrive from the URL, so anything at all can be in them, and an
     * unparseable string handed to the database would be a 500 rather than a
     * report.
     */
    private static function parse(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') {
            return $fallback->startOfDay();
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return $fallback->startOfDay();
        }
    }
}
