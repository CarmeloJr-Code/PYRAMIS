<?php

namespace App\Enums;

/**
 * Why finished stock changed at a location.
 *
 * The same shape as the ingredient ledger's types, for the same reason: stock
 * is the sum of its movements, so every change has to say what it was.
 */
enum ProductStockMovementType: string
{
    case Produced = 'produced';

    case Transfer = 'transfer';

    case Adjustment = 'adjustment';

    /**
     * The label shown in the ledger.
     */
    public function label(): string
    {
        return match ($this) {
            self::Produced => 'Produced',
            self::Transfer => 'Transfer',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * The only direction this type may move stock in, or null when it may go
     * either way.
     *
     * Production is finished goods arriving on the shelf. A transfer is one
     * half of a restock — off the main branch, onto an outlet — so it goes
     * either way, as does an adjustment, which is a correction: a miscount, or
     * a tray dropped.
     */
    public function fixedDirection(): ?int
    {
        return match ($this) {
            self::Produced => 1,
            self::Transfer, self::Adjustment => null,
        };
    }

    /**
     * Whether this type may add stock.
     */
    public function allowsIncrease(): bool
    {
        return $this->fixedDirection() !== -1;
    }

    /**
     * Whether this type may reduce stock.
     */
    public function allowsDecrease(): bool
    {
        return $this->fixedDirection() !== 1;
    }

    /**
     * Whether a reason has to be given.
     *
     * An adjustment is someone overriding the ledger by hand, which is the entry
     * an auditor will ask about. A bake explains itself.
     */
    public function requiresNote(): bool
    {
        return $this === self::Adjustment;
    }
}
