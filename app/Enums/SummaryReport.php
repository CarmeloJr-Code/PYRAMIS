<?php

namespace App\Enums;

/**
 * A workspace tab that can be printed as a summary PDF.
 *
 * Only the operational tabs: the catalogue has no period to summarise, the
 * forecast is a recommendation rather than a record (BR-008), the reports are
 * reports already, and chat is private to the people in it.
 */
enum SummaryReport: string
{
    case Dashboard = 'dashboard';

    case Orders = 'orders';

    case Sales = 'sales';

    case Expenses = 'expenses';

    case Inventory = 'inventory';

    case Restocking = 'restocking';

    case Production = 'production';

    case Workforce = 'workforce';

    case Outlets = 'outlets';

    case Schedule = 'schedule';

    /**
     * The heading the PDF carries.
     */
    public function title(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard summary',
            self::Orders => 'Orders summary',
            self::Sales => 'Sales summary',
            self::Expenses => 'Expenses summary',
            self::Inventory => 'Inventory summary',
            self::Restocking => 'Restocking summary',
            self::Production => 'Production summary',
            self::Workforce => 'Workforce summary',
            self::Outlets => 'Outlets summary',
            self::Schedule => 'My schedule summary',
        };
    }

    /**
     * The ability guarding the tab it summarises, so the PDF is never wider
     * than the screen. Null for the dashboard, which every employee opens and
     * which narrows itself section by section.
     */
    public function ability(): ?string
    {
        return match ($this) {
            self::Dashboard => null,
            self::Orders => 'manage-orders',
            self::Sales => 'access-sales',
            self::Expenses => 'access-expenses',
            self::Inventory => 'access-inventory',
            self::Restocking => 'access-restocking',
            self::Production => 'access-production',
            self::Workforce => 'access-workforce',
            self::Outlets => 'manage-outlets',
            self::Schedule => 'view-own-schedule',
        };
    }
}
