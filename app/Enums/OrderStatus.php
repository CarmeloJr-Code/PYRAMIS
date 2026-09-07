<?php

namespace App\Enums;

/**
 * The lifecycle of a customer pre-order.
 *
 * Slice 3a only ever creates Pending — the cashier transitions belong to 3b.
 */
enum OrderStatus: string
{
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Preparing = 'preparing';

    case Ready = 'ready';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    /**
     * The status this order moves to next, or null at the end of the line.
     *
     * The spec names the six states but not the moves between them, so the
     * workflow is deliberately narrow: one step forward at a time, which keeps
     * the history trustworthy for Phase 11 reporting.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Confirmed,
            self::Confirmed => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Completed,
            self::Completed, self::Cancelled => null,
        };
    }

    /**
     * Whether this order has finished its life, one way or the other.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], strict: true);
    }

    /**
     * Whether a move to the given status is allowed.
     *
     * Only the next step forward, or a cancellation of anything still open.
     * Everything else — skipping ahead, going back, reopening a finished order
     * — is refused.
     */
    public function canTransitionTo(self $status): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $status === self::Cancelled || $status === $this->next();
    }

    /**
     * The label shown to customers and staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready for pickup',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
