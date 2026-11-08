<?php

namespace App\Enums;

use App\Models\Ingredient;

/**
 * What a recipe line's quantity is written in.
 *
 * Stock is always held in the ingredient's purchasing unit; this only says how
 * the recipe card reads. Anything other than Unit is converted back to that
 * unit through the ingredient's own figures before it touches the ledger.
 *
 * Base is grams for something weighed and milliliters for something poured,
 * decided by the ingredient rather than stored here, so a line can never say
 * grams for a liquid.
 */
enum RecipeMeasure: string
{
    case Unit = 'unit';

    case Base = 'base';

    case Cup = 'cup';

    case Tablespoon = 'tablespoon';

    case Teaspoon = 'teaspoon';

    /**
     * How many of this measure make one cup, or null when it is not a cup or
     * spoon.
     *
     * The standard kitchen ratios: 16 tablespoons or 48 teaspoons to the cup.
     */
    public function perCup(): ?int
    {
        return match ($this) {
            self::Cup => 1,
            self::Tablespoon => 16,
            self::Teaspoon => 48,
            self::Unit, self::Base => null,
        };
    }

    /**
     * The short form shown next to a quantity of the given ingredient.
     */
    public function abbreviation(Ingredient $ingredient): string
    {
        return match ($this) {
            self::Unit => $ingredient->unit->abbreviation(),
            self::Base => $ingredient->baseMeasure()?->abbreviation() ?? 'g',
            self::Cup => 'cup',
            self::Tablespoon => 'tbsp',
            self::Teaspoon => 'tsp',
        };
    }
}
