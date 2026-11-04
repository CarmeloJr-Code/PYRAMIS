<?php

namespace App\Enums;

/**
 * Where an ingredient's stock stands, as staff see it.
 *
 * Worked out by Ingredient::stockLevel() from the thresholds the model already
 * owns, so the inventory screens and the inventory report cannot drift into
 * different words or colours for the same state.
 */
enum StockLevel
{
    case NotStocked;

    case OutOfStock;

    case Low;

    case InStock;

    /**
     * The label shown to staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotStocked => 'Not stocked',
            self::OutOfStock => 'Out of stock',
            self::Low => 'Low',
            self::InStock => 'In stock',
        };
    }

    /**
     * The badge colour this level is drawn in.
     */
    public function color(): string
    {
        return match ($this) {
            self::NotStocked => 'zinc',
            self::OutOfStock => 'red',
            self::Low => 'amber',
            self::InStock => 'green',
        };
    }

    /**
     * The icon that marks a level needing action, so it reads without colour.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::OutOfStock, self::Low => 'exclamation-triangle',
            self::NotStocked, self::InStock => null,
        };
    }
}
