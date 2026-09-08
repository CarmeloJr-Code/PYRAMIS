<?php

namespace App\Enums;

/**
 * Why an ingredient's stock changed.
 *
 * Every stock-changing action leaves one of these behind, so a quantity is
 * always the sum of its movements rather than a number someone overwrote
 * (Phase 5 exit criterion). Production usage joins this list in Phase 6, when
 * something actually consumes ingredients.
 */
enum InventoryMovementType: string
{
    case Received = 'received';

    case Adjustment = 'adjustment';

    /**
     * The label shown in the ledger.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Whether this type may reduce stock.
     *
     * A receipt is stock arriving, so it only ever goes up; an adjustment is a
     * correction and can go either way.
     */
    public function allowsDecrease(): bool
    {
        return $this === self::Adjustment;
    }

    /**
     * Whether a reason has to be given.
     *
     * An adjustment is someone overriding the ledger by hand, which is exactly
     * the entry an auditor will ask about.
     */
    public function requiresNote(): bool
    {
        return $this === self::Adjustment;
    }
}
