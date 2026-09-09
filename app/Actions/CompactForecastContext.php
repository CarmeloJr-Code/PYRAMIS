<?php

namespace App\Actions;

/**
 * The deterministic outlook, cut down to a brief small enough to send.
 *
 * Groq's free allowance is 8,000 tokens a minute, and BuildForecastContext
 * deliberately returns the whole catalogue and every active ingredient — a size
 * that sold nothing is a planning fact. Encoding all of that as JSON runs to
 * thousands of tokens on a real catalogue, mostly repeated keys and rows with
 * nothing to say, and the second reading in a minute would be refused.
 *
 * So this takes what matters: everything small in full, and of the two long
 * tables only the rows the deterministic layer already flagged. It is a `take`,
 * never a re-sort — BuildForecastContext put products in shortfall order and
 * ingredients in attention order, and duplicating that logic here would let the
 * two disagree.
 *
 * What was left out is stated in a line under each table rather than silently
 * dropped, so "the rest are covered" is something the model was told instead of
 * something it has to infer. Nothing here talks to a model; it only prepares
 * what one is shown.
 */
final class CompactForecastContext
{
    /**
     * The most product rows a brief will carry.
     */
    public const MAX_PRODUCTS = 10;

    /**
     * The fewest it will carry, when little or nothing is short.
     *
     * A well-stocked week would otherwise show an empty table, and a model given
     * nothing tends to invent something.
     */
    public const MIN_PRODUCTS = 5;

    /**
     * The most ingredient rows a brief will carry.
     */
    public const MAX_INGREDIENTS = 8;

    /**
     * Lay the outlook out as text a model can read cheaply.
     *
     * @param  array<string, mixed>  $context  As BuildForecastContext returned it.
     */
    public function handle(array $context): string
    {
        return implode("\n\n", array_filter([
            $this->ranges($context),
            $this->demand($context['demand']),
            $this->production($context['production']),
            $this->weekdays($context['weekdays']),
            $this->products($context['products']),
            $this->ingredients($context['ingredients']),
        ]));
    }

    /**
     * What stretch was read, and what stretch is being projected over.
     *
     * @param  array<string, mixed>  $context
     */
    private function ranges(array $context): string
    {
        return sprintf(
            "WINDOW %s to %s (%d days)\nHORIZON %s to %s (%d days)",
            $context['window']['from'],
            $context['window']['to'],
            $context['window']['days'],
            $context['horizon']['from'],
            $context['horizon']['to'],
            $context['horizon']['days'],
        );
    }

    /**
     * What the counter did, and what the rate carries forward to.
     *
     * Takings are deliberately left out. It is the one money figure in the
     * context, and handing a model revenue invites revenue advice — which is
     * outside decision support and next door to the spending BR-009 forbids.
     *
     * @param  array<string, mixed>  $demand
     */
    private function demand(array $demand): string
    {
        $lines = [sprintf(
            'DEMAND over the window: %s units sold across %s sales, %s units a day.',
            number_format($demand['units_sold']),
            number_format($demand['sales']),
            number_format($demand['daily_average'], 2),
        )];

        $lines[] = sprintf(
            'The recent half sold %s units against %s in the half before%s.',
            number_format($demand['recent_units']),
            number_format($demand['previous_units']),
            $demand['change'] === null ? '' : sprintf(' (%+.1f%%)', $demand['change']),
        );

        // "Projected demand", never a bare "projected": a model shown the figure
        // without the noun reads it back as a shortfall, which it is not.
        $lines[] = sprintf(
            'Trend factor x%s, held between x0.5 and x2.0. Projected demand over the horizon: %s units — this is how much is expected to sell, not a gap to be filled.',
            number_format($demand['trend_factor'], 3),
            number_format($demand['projected_units']),
        );

        return implode("\n", $lines);
    }

    /**
     * What came out of the oven over the same stretch.
     *
     * @param  array<string, mixed>  $production
     */
    private function production(array $production): string
    {
        return sprintf(
            'PRODUCTION over the window: %s units baked over %s runs, %s units a day.',
            number_format($production['units_produced']),
            number_format($production['runs']),
            number_format($production['daily_average'], 2),
        );
    }

    /**
     * The shape of a week, on one line.
     *
     * @param  list<array<string, mixed>>  $weekdays
     */
    private function weekdays(array $weekdays): string
    {
        if ($weekdays === []) {
            return '';
        }

        $parts = array_map(
            fn (array $day): string => sprintf(
                '%s %s',
                substr($day['weekday'], 0, 3),
                number_format($day['average'], 1),
            ),
            $weekdays,
        );

        return "AVERAGE UNITS A DAY BY WEEKDAY\n".implode(' | ', $parts);
    }

    /**
     * The sizes worth a manager's attention, and a word about the rest.
     *
     * Record ids never appear. The model has no business naming one, and a
     * hallucinated id in a recommendation would be far worse than a
     * hallucinated name — which the page can at least check against the
     * catalogue.
     *
     * @param  list<array<string, mixed>>  $products
     */
    private function products(array $products): string
    {
        if ($products === []) {
            return 'PRODUCTS: no size is currently available for sale.';
        }

        $short = array_values(array_filter(
            $products,
            fn (array $product): bool => $product['shortfall'] > 0,
        ));

        $shown = array_slice($short, 0, self::MAX_PRODUCTS);

        // Nothing short is good news, not an empty table. Fall back to whatever
        // is moving most, so the reading still has something real to work with.
        if (count($shown) < self::MIN_PRODUCTS) {
            $shown = $this->toppedUp($shown, $products, self::MIN_PRODUCTS);
        }

        $rows = array_map(fn (array $product): string => implode(' | ', [
            $product['product'].' '.$product['size'],
            number_format($product['units_sold']),
            number_format($product['daily_average'], 2),
            'x'.number_format($product['trend_factor'], 2),
            number_format($product['projected_units']),
            number_format($product['stock_on_hand']),
            number_format($product['shortfall']),
            $product['has_recipe'] ? 'yes' : 'no',
        ]), $shown);

        $heading = sprintf(
            'PRODUCTS — %d of %d sizes, those projected short first',
            count($shown),
            count($products),
        );

        $table = "size | sold | a day | trend | projected | in stock | short | recipe\n"
            .implode("\n", $rows);

        return $heading."\n".$table.$this->productsOmitted($products, $shown);
    }

    /**
     * Rows padded out with the biggest projections not already shown.
     *
     * @param  list<array<string, mixed>>  $shown
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function toppedUp(array $shown, array $products, int $target): array
    {
        $names = array_map($this->identify(...), $shown);

        $rest = array_values(array_filter(
            $products,
            fn (array $product): bool => ! in_array($this->identify($product), $names, strict: true),
        ));

        usort($rest, fn (array $a, array $b): int => $b['projected_units'] <=> $a['projected_units']);

        return array_merge($shown, array_slice($rest, 0, max(0, $target - count($shown))));
    }

    /**
     * A line about the sizes that did not make the table.
     *
     * The table is capped, so what was left out is not automatically covered —
     * when more sizes are short than fit, the overflow is short too. Calling
     * those "covered" would be a plain untruth about the bakery's stock, and
     * the model has no way to catch it.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $shown
     */
    private function productsOmitted(array $products, array $shown): string
    {
        $names = array_map($this->identify(...), $shown);

        $omitted = array_values(array_filter(
            $products,
            fn (array $product): bool => ! in_array($this->identify($product), $names, strict: true),
        ));

        if ($omitted === []) {
            return '';
        }

        $short = array_values(array_filter(
            $omitted,
            fn (array $product): bool => $product['shortfall'] > 0,
        ));

        $covered = count($omitted) - count($short);

        if ($short === []) {
            return sprintf(
                "\nThe other %d %s covered; the largest of them projects %s units.",
                $covered,
                $covered === 1 ? 'size is' : 'sizes are',
                number_format(max(array_map(
                    fn (array $product): int => $product['projected_units'],
                    $omitted,
                ))),
            );
        }

        $largestShort = max(array_map(fn (array $product): int => $product['shortfall'], $short));

        return sprintf(
            "\n%d further %s short as well, the largest by %s units.%s",
            count($short),
            count($short) === 1 ? 'size is' : 'sizes are',
            number_format($largestShort),
            $covered > 0
                ? sprintf(' The remaining %d %s covered.', $covered, $covered === 1 ? 'is' : 'are')
                : '',
        );
    }

    /**
     * What the stockroom needs looked at, and a word about the rest.
     *
     * Only the rows BuildForecastContext already flagged: low, out, or short for
     * the horizon. The reorder level itself is left out — the standing note
     * already carries what it means.
     *
     * @param  list<array<string, mixed>>  $ingredients
     */
    private function ingredients(array $ingredients): string
    {
        if ($ingredients === []) {
            return 'INGREDIENTS: nothing has been added to the stockroom.';
        }

        $attention = array_values(array_filter(
            $ingredients,
            fn (array $ingredient): bool => $ingredient['needs_attention'],
        ));

        if ($attention === []) {
            return sprintf(
                'INGREDIENTS: all %d are stocked above their reorder level and cover the horizon.%s',
                count($ingredients),
                $this->tightestCover($ingredients),
            );
        }

        $shown = array_slice($attention, 0, self::MAX_INGREDIENTS);

        $rows = array_map(fn (array $ingredient): string => implode(' | ', [
            $ingredient['name'].' ('.$ingredient['unit'].')',
            number_format($ingredient['stock'], 2),
            number_format($ingredient['daily_usage'], 2),
            number_format($ingredient['needed_for_horizon'], 2),
            number_format($ingredient['shortfall'], 2),
            $ingredient['days_of_cover'] === null
                ? 'no usage'
                : number_format($ingredient['days_of_cover'], 1),
            $ingredient['below_reorder'] ? 'below reorder level' : 'short for the horizon',
        ]), $shown);

        $heading = sprintf(
            'INGREDIENTS — %d of %d needing attention, most urgent first',
            count($shown),
            count($ingredients),
        );

        $table = "name | stock | used a day | needed | short | days of cover | standing\n"
            .implode("\n", $rows);

        $omitted = count($ingredients) - count($shown);

        $rest = $omitted > 0
            ? sprintf(
                "\nThe other %d %s covered.%s",
                $omitted,
                $omitted === 1 ? 'is' : 'are',
                $this->tightestCover(array_slice($ingredients, count($shown))),
            )
            : '';

        return $heading."\n".$table.$rest;
    }

    /**
     * How close the tightest of a set of ingredients is to running out.
     *
     * The number that decides whether "all covered" is comfortable or only just
     * true, so the model is told it rather than left to assume comfort.
     *
     * @param  list<array<string, mixed>>  $ingredients
     */
    private function tightestCover(array $ingredients): string
    {
        $cover = array_filter(array_map(
            fn (array $ingredient): ?float => $ingredient['days_of_cover'],
            $ingredients,
        ), fn (?float $days): bool => $days !== null);

        if ($cover === []) {
            return '';
        }

        return sprintf(' The tightest has %s days of cover.', number_format(min($cover), 1));
    }

    /**
     * A size's name, as the brief spells it.
     *
     * @param  array<string, mixed>  $product
     */
    private function identify(array $product): string
    {
        return $product['product'].' '.$product['size'];
    }
}
