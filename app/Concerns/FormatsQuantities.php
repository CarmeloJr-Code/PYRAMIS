<?php

namespace App\Concerns;

/**
 * Renders an ingredient quantity for reading.
 *
 * Quantities are held as thousandths for the same reason money is held as
 * centavos: the production image installs no bcmath, so arithmetic stays on
 * integers and only the display step divides. Trailing zeros are trimmed —
 * "12" and "1.5" read better than "12.000" and "1.500", and a decimal place
 * still shows when it carries information.
 */
trait FormatsQuantities
{
    /**
     * How many decimal places a quantity is stored to.
     */
    private const QUANTITY_SCALE = 3;

    /**
     * Format a quantity held in thousandths.
     */
    public static function formatQuantity(int $thousandths): string
    {
        $formatted = number_format(
            $thousandths / (10 ** self::QUANTITY_SCALE),
            self::QUANTITY_SCALE,
        );

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    /**
     * Render a quantity held in thousandths as a plain decimal.
     *
     * For storing and comparing, where a thousands separator would be a bug
     * rather than a courtesy.
     */
    public static function quantityFromThousandths(int $thousandths): string
    {
        return number_format(
            $thousandths / (10 ** self::QUANTITY_SCALE),
            self::QUANTITY_SCALE,
            '.',
            '',
        );
    }

    /**
     * Convert a decimal quantity to the integer thousandths worked in.
     */
    public static function quantityToThousandths(float|string $quantity): int
    {
        return (int) round((float) $quantity * (10 ** self::QUANTITY_SCALE));
    }
}
