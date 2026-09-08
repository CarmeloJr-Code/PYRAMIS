<?php

namespace App\Enums;

/**
 * The life of a main-branch-to-outlet restock.
 *
 * The spec's chain — request/schedule, preparation, delivery — with a
 * cancellation for anything called off before it lands. The moves are as narrow
 * as an order's, and for the same reason: a history that can only go forwards
 * one step is the one Phase 11 can report on.
 */
enum RestockStatus: string
{
    case Requested = 'requested';

    case Preparing = 'preparing';

    case Delivered = 'delivered';

    case Cancelled = 'cancelled';

    /**
     * The status this restock moves to next, or null at the end of the line.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Requested => self::Preparing,
            self::Preparing => self::Delivered,
            self::Delivered, self::Cancelled => null,
        };
    }

    /**
     * Whether this restock has finished its life, one way or the other.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], strict: true);
    }

    /**
     * Whether a move to the given status is allowed.
     *
     * Only the next step forward, or a cancellation of anything not yet
     * delivered. Once stock has moved, nothing may reopen it.
     */
    public function canTransitionTo(self $status): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $status === self::Cancelled || $status === $this->next();
    }

    /**
     * The label shown to staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Preparing => 'Preparing',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The badge colour this status is drawn in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Preparing => 'blue',
            self::Delivered => 'green',
            self::Cancelled => 'zinc',
        };
    }
}
