<?php

namespace App\Enums;

/**
 * When a storefront order is to be collected, as the customer chose it.
 *
 * Today is "Buy now": collected the same day, and only while today's stock
 * lasts. Later is a pre-order for tomorrow or after, baked on top of the day's
 * retail amount, so it never counts against what the storefront shows. One
 * order has one pickup, so the choice belongs to the whole cart.
 */
enum PickupWhen: string
{
    case Today = 'today';

    case Later = 'later';

    /**
     * The earliest and latest pickup times a customer may book, 8 AM to 7 PM.
     */
    public const OPENS = '08:00';

    public const CLOSES = '19:00';

    /**
     * Whether today still has a pickup hour to book — the last one has not
     * gone by.
     */
    public static function todayHasPickupsLeft(): bool
    {
        return ! today()->setTimeFromTimeString(self::CLOSES)->isPast();
    }

    /**
     * The label the customer chooses between.
     */
    public function label(): string
    {
        return match ($this) {
            self::Today => __('Pick up today'),
            self::Later => __('Pre-order for a later day'),
        };
    }
}
