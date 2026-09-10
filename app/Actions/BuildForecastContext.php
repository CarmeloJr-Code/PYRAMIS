<?php

namespace App\Actions;

use App\Concerns\FormatsQuantities;
use App\Concerns\ReportRange;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The deterministic half of forecasting: what the records say, worked out in
 * PHP and SQL.
 *
 * The arithmetic lives here and never goes to a language model — totals, daily
 * averages, the shape of a week and the gap between projected demand and stock
 * on hand are all things a computer can be right about, and a model asked to
 * do them would only be right by accident (docs/ai-forecasting.md).
 *
 * Nothing here writes. The result is a reading of the past offered as context
 * for a decision, never a promise about the future (BR-008, BR-009).
 */
final class BuildForecastContext
{
    use FormatsQuantities;

    /**
     * How far back the outlook reads by default — eight weeks, so every
     * weekday has eight observations behind its average.
     */
    public const LOOKBACK_DAYS = 56;

    /**
     * How far forward it projects by default.
     */
    public const HORIZON_DAYS = 7;

    /**
     * The lookbacks a manager may choose.
     *
     * An allow-list rather than a range: these arrive from the query string,
     * and a projection over an arbitrary number of days would invite a figure
     * nobody can sanity-check.
     *
     * @var list<int>
     */
    public const LOOKBACKS = [28, 56, 84];

    /** @var list<int> */
    public const HORIZONS = [7, 14];

    /**
     * A trend is allowed to halve or double a rate, and no more.
     *
     * One unusual stretch — a fiesta order, a week closed — would otherwise
     * project a rate the bakery has never run at. The clamp is stated on the
     * screen, so a manager can see that it was applied.
     */
    private const TREND_FLOOR = 0.5;

    private const TREND_CEILING = 2.0;

    /**
     * Read the records and lay out what they say.
     *
     * @return array{
     *     window: array{from: string, to: string, days: int, label: string},
     *     horizon: array{from: string, to: string, days: int, label: string},
     *     demand: array{units_sold: int, sales: int, takings: int, daily_average: float, recent_units: int, previous_units: int, change: float|null, trend_factor: float, projected_units: int},
     *     production: array{units_produced: int, runs: int, daily_average: float},
     *     weekdays: list<array{weekday: string, days_observed: int, units: int, average: float}>,
     *     products: list<array{product_variant_id: int, product: string, size: string, units_sold: int, daily_average: float, recent_units: int, previous_units: int, change: float|null, trend_factor: float, projected_units: int, stock_on_hand: int, shortfall: int, has_recipe: bool}>,
     *     ingredients: list<array{ingredient_id: int, name: string, unit: string, stock: float, daily_usage: float, needed_for_horizon: float, shortfall: float, days_of_cover: float|null, reorder_level: float, below_reorder: bool, needs_attention: bool}>
     * }
     */
    public function handle(int $lookbackDays = self::LOOKBACK_DAYS, int $horizonDays = self::HORIZON_DAYS): array
    {
        $lookbackDays = in_array($lookbackDays, self::LOOKBACKS, true) ? $lookbackDays : self::LOOKBACK_DAYS;
        $horizonDays = in_array($horizonDays, self::HORIZONS, true) ? $horizonDays : self::HORIZON_DAYS;

        $today = CarbonImmutable::now()->startOfDay();

        $window = ReportRange::between(
            $today->subDays($lookbackDays - 1)->toDateString(),
            $today->toDateString(),
        );

        // Tomorrow onwards: today is already partly inside the window, and
        // projecting over a day half spent would count it twice.
        $horizon = ReportRange::between(
            $today->addDay()->toDateString(),
            $today->addDays($horizonDays)->toDateString(),
        );

        // The window split in two, so "recent" is measured against a stretch of
        // the same length rather than against the whole of itself.
        $half = intdiv($lookbackDays, 2);
        $recent = ReportRange::between($today->subDays($half - 1)->toDateString(), $today->toDateString());
        $previous = ReportRange::between(
            $today->subDays($lookbackDays - 1)->toDateString(),
            $today->subDays($half)->toDateString(),
        );

        // One pass of the daily series, shared by the totals and the weekday
        // shape, so the two can never disagree.
        $daily = Sale::dailyTakings($window->from(), $window->to());

        return [
            'window' => $this->describe($window),
            'horizon' => $this->describe($horizon),
            'demand' => $this->demand($daily, $window, $recent, $previous, $horizonDays),
            'production' => $this->production($window),
            'weekdays' => $this->weekdays($daily, $window),
            'products' => $this->products($window, $recent, $previous, $horizonDays),
            'ingredients' => $this->ingredients($window, $horizonDays),
        ];
    }

    /**
     * A range in the shape the context reports it.
     *
     * @return array{from: string, to: string, days: int, label: string}
     */
    private function describe(ReportRange $range): array
    {
        return [
            'from' => $range->from(),
            'to' => $range->to(),
            'days' => $range->days(),
            'label' => $range->label(),
        ];
    }

    /**
     * What the counter did, and what that rate carries forward to.
     *
     * @param  Collection<int, array{day: string, sales: int, units: int, takings: int}>  $daily
     * @return array{units_sold: int, sales: int, takings: int, daily_average: float, recent_units: int, previous_units: int, change: float|null, trend_factor: float, projected_units: int}
     */
    private function demand(Collection $daily, ReportRange $window, ReportRange $recent, ReportRange $previous, int $horizonDays): array
    {
        $units = (int) $daily->sum('units');
        $recentUnits = $this->unitsWithin($daily, $recent);
        $previousUnits = $this->unitsWithin($daily, $previous);
        $factor = $this->trendFactor($recentUnits, $previousUnits);

        // Divided by every day in the window, not by the days that had a sale:
        // a quiet Monday is a quiet Monday, not a day that never happened.
        $average = round($units / $window->days(), 2);

        return [
            'units_sold' => $units,
            'sales' => Sale::countCompleted($window->from(), $window->to()),
            'takings' => Sale::takingsInCentavos($window->from(), $window->to()),
            'daily_average' => $average,
            'recent_units' => $recentUnits,
            'previous_units' => $previousUnits,
            'change' => $window->change($recentUnits, $previousUnits),
            'trend_factor' => $factor,
            'projected_units' => (int) round($average * $factor * $horizonDays),
        ];
    }

    /**
     * What came out of the oven over the same stretch, for comparison.
     *
     * @return array{units_produced: int, runs: int, daily_average: float}
     */
    private function production(ReportRange $window): array
    {
        $produced = ProductionRun::unitsProduced($window->from(), $window->to());

        return [
            'units_produced' => $produced,
            'runs' => ProductionRun::runsLogged($window->from(), $window->to()),
            'daily_average' => round($produced / $window->days(), 2),
        ];
    }

    /**
     * The shape of a week, which for a bakery is most of the story.
     *
     * Each weekday's units are divided by how many times that weekday actually
     * fell inside the window — not by the days that had sales, which would let
     * a closed Monday raise the Monday average.
     *
     * @param  Collection<int, array{day: string, sales: int, units: int, takings: int}>  $daily
     * @return list<array{weekday: string, days_observed: int, units: int, average: float}>
     */
    private function weekdays(Collection $daily, ReportRange $window): array
    {
        $unitsByDay = $daily->keyBy('day')->map(fn (array $row): int => $row['units']);

        /** @var array<int, array{weekday: string, units: int, days: int}> $buckets */
        $buckets = [];

        for ($day = $window->start; $day->lessThanOrEqualTo($window->end); $day = $day->addDay()) {
            $index = (int) $day->dayOfWeek;

            $buckets[$index] ??= ['weekday' => $day->format('l'), 'units' => 0, 'days' => 0];
            $buckets[$index]['units'] += $unitsByDay->get($day->toDateString(), 0);
            $buckets[$index]['days']++;
        }

        ksort($buckets);

        $weekdays = [];

        foreach ($buckets as $bucket) {
            $weekdays[] = [
                'weekday' => $bucket['weekday'],
                'days_observed' => $bucket['days'],
                'units' => $bucket['units'],
                'average' => round($bucket['units'] / $bucket['days'], 2),
            ];
        }

        return $weekdays;
    }

    /**
     * Every size the bakery can sell, with what it sold and what it holds.
     *
     * The whole catalogue rather than only what moved: a size that sold nothing
     * is a planning fact, and leaving it out would hide it.
     *
     * @return list<array{product_variant_id: int, product: string, size: string, units_sold: int, daily_average: float, recent_units: int, previous_units: int, change: float|null, trend_factor: float, projected_units: int, stock_on_hand: int, shortfall: int, has_recipe: bool}>
     */
    private function products(ReportRange $window, ReportRange $recent, ReportRange $previous, int $horizonDays): array
    {
        $sold = Sale::unitsSoldByVariant($window->from(), $window->to())->keyBy('product_variant_id');
        $recentSold = Sale::unitsSoldByVariant($recent->from(), $recent->to())->keyBy('product_variant_id');
        $previousSold = Sale::unitsSoldByVariant($previous->from(), $previous->to())->keyBy('product_variant_id');

        // "Available for sale" means the same thing here as at the counter and
        // in the storefront: the size is available and its product is still
        // published. Reading only the size would put a withdrawn product in a
        // table headed "what to consider baking".
        $variants = ProductVariant::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('product')
            ->withStockEverywhere()
            ->withExists('recipe as has_recipe')
            ->get();

        $products = [];

        foreach ($variants as $variant) {
            $units = $sold->get($variant->id)['units'] ?? 0;
            $recentUnits = $recentSold->get($variant->id)['units'] ?? 0;
            $previousUnits = $previousSold->get($variant->id)['units'] ?? 0;

            $average = round($units / $window->days(), 2);
            $factor = $this->trendFactor($recentUnits, $previousUnits);
            $projected = (int) round($average * $factor * $horizonDays);
            $stock = $variant->stockInUnits();

            $products[] = [
                'product_variant_id' => $variant->id,
                'product' => $variant->product->name,
                'size' => $variant->name,
                'units_sold' => $units,
                'daily_average' => $average,
                'recent_units' => $recentUnits,
                'previous_units' => $previousUnits,
                'change' => $window->change($recentUnits, $previousUnits),
                'trend_factor' => $factor,
                'projected_units' => $projected,
                'stock_on_hand' => $stock,
                // What the projection asks for beyond what is already made. A
                // suggestion for a manager to weigh, never a production record
                // (BR-009).
                'shortfall' => max(0, $projected - $stock),
                'has_recipe' => (bool) $variant->getAttribute('has_recipe'),
            ];
        }

        usort($products, fn (array $a, array $b): int => $b['shortfall'] <=> $a['shortfall']);

        return $products;
    }

    /**
     * What the stockroom is holding against what the ovens have been drawing.
     *
     * Quantities come back in each ingredient's own unit rather than in
     * thousandths: this is read by people, and later by a model, and neither
     * should have to know how the column is stored.
     *
     * @return list<array{ingredient_id: int, name: string, unit: string, stock: float, daily_usage: float, needed_for_horizon: float, shortfall: float, days_of_cover: float|null, reorder_level: float, below_reorder: bool, needs_attention: bool}>
     */
    private function ingredients(ReportRange $window, int $horizonDays): array
    {
        $movement = InventoryMovement::summaryByIngredient($window->from(), $window->to())
            ->keyBy('ingredient_id');

        $ingredients = [];

        foreach (Ingredient::query()->active()->withStock()->orderBy('name')->get() as $ingredient) {
            // Usage is stored as a negative; a rate of consumption is not.
            $used = abs($movement->get($ingredient->id)['used'] ?? 0);
            $dailyUsage = (int) round($used / $window->days());
            $needed = $dailyUsage * $horizonDays;
            $stock = $ingredient->stockInThousandths();
            $short = $dailyUsage > 0 && $stock < $needed;

            $ingredients[] = [
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'unit' => $ingredient->unit->abbreviation(),
                'stock' => $this->asUnits($stock),
                'daily_usage' => $this->asUnits($dailyUsage),
                'needed_for_horizon' => $this->asUnits($needed),
                'shortfall' => $this->asUnits(max(0, $needed - $stock)),
                // Null, not zero: an ingredient nothing has drawn on has no
                // rate to run out at, and "0 days of cover" would read as an
                // emergency.
                'days_of_cover' => $dailyUsage > 0 ? round($stock / $dailyUsage, 1) : null,
                'reorder_level' => $this->asUnits($ingredient->reorderLevelInThousandths()),
                'below_reorder' => $ingredient->isLowStock() || $ingredient->isOutOfStock(),
                'needs_attention' => $ingredient->isLowStock() || $ingredient->isOutOfStock() || $short,
            ];
        }

        usort($ingredients, function (array $a, array $b): int {
            if ($a['needs_attention'] !== $b['needs_attention']) {
                return $b['needs_attention'] <=> $a['needs_attention'];
            }

            return ($a['days_of_cover'] ?? PHP_INT_MAX) <=> ($b['days_of_cover'] ?? PHP_INT_MAX);
        });

        return $ingredients;
    }

    /**
     * How much a rate has moved, held inside the clamp.
     */
    private function trendFactor(int $recent, int $previous): float
    {
        if ($previous === 0) {
            return $recent > 0 ? self::TREND_CEILING : 1.0;
        }

        return round(max(self::TREND_FLOOR, min(self::TREND_CEILING, $recent / $previous)), 3);
    }

    /**
     * Units sold over part of the window, taken from the series already read.
     *
     * @param  Collection<int, array{day: string, sales: int, units: int, takings: int}>  $daily
     */
    private function unitsWithin(Collection $daily, ReportRange $range): int
    {
        return (int) $daily
            ->filter(fn (array $row): bool => $row['day'] >= $range->from() && $row['day'] <= $range->to())
            ->sum('units');
    }

    /**
     * A stored quantity as a number in the ingredient's own unit.
     */
    private function asUnits(int $thousandths): float
    {
        return round($thousandths / (10 ** self::QUANTITY_SCALE), self::QUANTITY_SCALE);
    }
}
