<?php

namespace App\Enums;

/**
 * The state of a recorded sale.
 *
 * A mistake is voided rather than deleted, so the record stays auditable and
 * totals can exclude it (docs/database.md).
 */
enum SaleStatus: string
{
    case Completed = 'completed';

    case Voided = 'voided';

    /**
     * The label shown to staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Voided => 'Voided',
        };
    }
}
