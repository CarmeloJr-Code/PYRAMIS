<?php

namespace App\Enums;

/**
 * The units an ingredient is stocked in.
 *
 * Deliberately purchasing units, not cooking measures: the supplied recipes
 * call for cups, tablespoons and teaspoons, but nothing is ever bought,
 * received or counted that way. Stock is held in what arrives from the
 * supplier.
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
}
