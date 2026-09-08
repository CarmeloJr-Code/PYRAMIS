<?php

namespace App\Enums;

/**
 * Why an ingredient's stock changed.
 *
 * Every stock-changing action leaves one of these behind, so a quantity is
 * always the sum of its movements rather than a number someone overwrote
 * (Phase 5 exit criterion).
 *
 * Usage is kept apart from Adjustment on purpose: an adjustment says the count
 * was wrong, usage says the bakery baked with it. Only the second is worth
 * anything to forecasting, and collapsing them would make both meaningless.
 */
enum InventoryMovementType: string
{
    case Received = 'received';

    case Usage = 'usage';

    case Adjustment = 'adjustment';

    /**
     * The label shown in the ledger.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Usage => 'Used',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * The only direction this type may move stock in, or null when it may go
     * either way.
     *
     * A receipt is stock arriving and usage is stock consumed, so neither has a
     * direction to choose. An adjustment is a correction, which can go either
     * way by definition.
     */
    public function fixedDirection(): ?int
    {
        return match ($this) {
            self::Received => 1,
            self::Usage => -1,
            self::Adjustment => null,
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
     * An adjustment is someone overriding the ledger by hand, which is exactly
     * the entry an auditor will ask about. A receipt and a bake speak for
     * themselves.
     */
    public function requiresNote(): bool
    {
        return $this === self::Adjustment;
    }
}
