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
