<?php

namespace App\Enums;

/**
 * The roles an authenticated PYRAMIS employee can hold.
 *
 * `Customer` is part of the role vocabulary in docs/roles-and-permissions.md but is
 * deliberately absent here: BR-001 states customers never hold an account, so it can
 * never be a `users.role` value.
 */
enum UserRole: string
{
    case Administrator = 'administrator';

    case Baker = 'baker';

    case Cashier = 'cashier';

    /**
     * The portal-design term for this role (docs/roles-and-permissions.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Manager',
            self::Baker => 'Production Staff',
            self::Cashier => 'Sales Staff',
        };
    }
}
