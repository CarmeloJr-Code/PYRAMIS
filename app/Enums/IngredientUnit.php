<?php

namespace App\Enums;

/**
 * The units an ingredient is stocked in.
 *
 * Deliberately purchasing units, not cooking measures: nothing is ever bought,
 * received or counted in cups or spoons, so stock is held in what arrives from
 * the supplier. A recipe may still be written in cups, spoons or grams — see
 * App\Enums\RecipeMeasure — and is converted back to this unit before it
 * draws on stock.
 */
enum IngredientUnit: string
{
    case Gram = 'gram';

    case Kilogram = 'kilogram';

    case Milliliter = 'milliliter';

    case Liter = 'liter';

    case Piece = 'piece';

    case Pack = 'pack';

    case Can = 'can';

    /**
     * The full name, for a picker.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gram => 'Grams',
            self::Kilogram => 'Kilograms',
            self::Milliliter => 'Milliliters',
            self::Liter => 'Liters',
            self::Piece => 'Pieces',
            self::Pack => 'Packs',
            self::Can => 'Cans',
        };
    }

    /**
     * The short form shown next to a quantity.
     */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Gram => 'g',
            self::Kilogram => 'kg',
            self::Milliliter => 'mL',
            self::Liter => 'L',
            self::Piece => 'pcs',
            self::Pack => 'packs',
            self::Can => 'cans',
        };
    }

    /**
     * The gram or milliliter this unit is a multiple of, or null when it is a
     * container whose contents have to be told.
     */
    public function baseMeasure(): ?self
    {
        return match ($this) {
            self::Gram, self::Kilogram => self::Gram,
            self::Milliliter, self::Liter => self::Milliliter,
            self::Piece, self::Pack, self::Can => null,
        };
    }

    /**
     * How many grams or milliliters one of this unit is, or null when the unit
     * does not say.
     */
    public function baseFactor(): ?int
    {
        return match ($this) {
            self::Gram, self::Milliliter => 1,
            self::Kilogram, self::Liter => 1000,
            self::Piece, self::Pack, self::Can => null,
        };
    }

    /**
     * Whether the business has to say how much one of this unit holds.
     */
    public function needsContent(): bool
    {
        return $this->baseMeasure() === null;
    }
}
