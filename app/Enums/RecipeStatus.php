<?php

namespace App\Enums;

/**
 * Where a recipe stands with the Administrator (BR-016).
 *
 * A Baker's recipe waits as Pending until an Administrator approves it or
 * sends it back; only an Approved recipe can be baked from. Saving a recipe
 * again starts the wait over, whatever it was before.
 */
enum RecipeStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /**
     * The label shown to staff.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Sent back',
        };
    }

    /**
     * The badge colour this status is drawn in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
        };
    }
}
