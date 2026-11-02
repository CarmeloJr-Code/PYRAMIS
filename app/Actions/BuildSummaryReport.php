<?php

namespace App\Actions;

use App\Concerns\FormatsQuantities;
use App\Concerns\ReportRange;
use App\Enums\OrderStatus;
use App\Enums\RestockStatus;
use App\Enums\SummaryReport;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductionRun;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The figures behind a tab's summary PDF.
 *
 * Every summary is the same shape — headline figures, then tables — so one
 * PDF template prints them all. The figures come from the same model queries
 * the tab and its report screen already use; nothing is counted a second way
 * here. Everything is formatted to strings, because a PDF is read, not
 * computed on.
 *
 * @phpstan-type Metric array{label: string, value: string}
 * @phpstan-type Table array{heading: string, columns: list<string>, rows: list<list<string>>, empty: string}
 * @phpstan-type Summary array{metrics: list<Metric>, tables: list<Table>}
 */
class BuildSummaryReport
{
    use FormatsQuantities;

    /**
     * Gather the summary for one tab over one stretch of days, as the given
     * employee is allowed to see it.
     *
     * @return Summary
     */
    public function handle(SummaryReport $report, ReportRange $range, User $user): array
    {
        return match ($report) {
            SummaryReport::Dashboard => $this->dashboard($range, $user),
            SummaryReport::Orders => $this->orders($range),
            SummaryReport::Sales => $this->sales($range),
            SummaryReport::Expenses => $this->expenses($range),
            SummaryReport::Inventory => $this->inventory($range),
            SummaryReport::Restocking => $this->restocking($range),
            SummaryReport::Production => $this->production($range),
            SummaryReport::Workforce => $this->workforce($range),
            SummaryReport::Outlets => $this->outlets($range),
            SummaryReport::Schedule => $this->schedule($range, $user),
        };
    }

    /**
     * The headline of every section the employee can open, and only those —
     * the same narrowing the dashboard screen does with its @can blocks.
     *
     * @return Summary
     */
    private function dashboard(ReportRange $range, User $user): array
    {
        $from = $range->from();
        $to = $range->to();
        $metrics = [];

        if (Gate::forUser($user)->allows('access-sales')) {
            $metrics[] = $this->metric('Takings', $this->peso(Sale::takingsInCentavos($from, $to)));
            $metrics[] = $this->metric('Sales', number_format(Sale::countCompleted($from, $to)));
        }

        if (Gate::forUser($user)->allows('access-expenses')) {
            $metrics[] = $this->metric('Expenses', $this->peso(Expense::totalSpentInCentavos($from, $to)));
        }

        if (Gate::forUser($user)->allows('manage-orders')) {
            $orders = Order::query()->whereDate('pickup_at', '>=', $from)->whereDate('pickup_at', '<=', $to);

            $metrics[] = $this->metric('Orders for pickup', number_format((clone $orders)->count()));
            $metrics[] = $this->metric('Orders completed', number_format($orders->where('status', OrderStatus::Completed)->count()));
        }

        if (Gate::forUser($user)->allows('access-production')) {
            $metrics[] = $this->metric('Units produced', number_format(ProductionRun::unitsProduced($from, $to)));
            $metrics[] = $this->metric('Production runs', number_format(ProductionRun::runsLogged($from, $to)));
        }

        if (Gate::forUser($user)->allows('access-inventory')) {
            $metrics[] = $this->metric('Ingredients low or out (now)', number_format($this->lowIngredients()->count()));
        }

        if (Gate::forUser($user)->allows('access-restocking')) {
            $metrics[] = $this->metric('Restocks delivered', number_format(Restock::query()->deliveredBetween($from, $to)->count()));
        }

        if (Gate::forUser($user)->allows('access-workforce')) {
            $metrics[] = $this->metric('Shifts rostered', number_format(Shift::query()->startingBetween($from, $to)->count()));
        }

        if (Gate::forUser($user)->allows('view-own-schedule')) {
            $metrics[] = $this->metric('My shifts', number_format($this->ownShifts($range, $user)->count()));
        }

        return ['metrics' => $metrics, 'tables' => []];
    }

    /**
     * Orders due for pickup over the range, dated by pickup because that is
     * the day the counter deals with them.
     *
     * The customer's phone and email stay off the page: a printout travels
     * further than the screen does.
     *
     * @return Summary
     */
    private function orders(ReportRange $range): array
    {
        $orders = Order::query()
            ->with(['outlet', 'items'])
            ->whereDate('pickup_at', '>=', $range->from())
            ->whereDate('pickup_at', '<=', $range->to())
            ->orderBy('pickup_at')
            ->orderBy('id')
            ->get();

        $completed = $orders->where('status', OrderStatus::Completed);
        $open = $orders->reject(fn (Order $order): bool => $order->status->isTerminal());

        $byStatus = collect(OrderStatus::cases())
            ->map(function (OrderStatus $status) use ($orders): array {
                $matching = $orders->where('status', $status);

                return [
                    $status->label(),
                    number_format($matching->count()),
                    $this->peso((int) $matching->sum(fn (Order $order): int => $order->totalInCentavos())),
                ];
            })
            ->all();

        return [
            'metrics' => [
                $this->metric('Orders for pickup', number_format($orders->count())),
                $this->metric('Completed', number_format($completed->count())),
                $this->metric('Still open', number_format($open->count())),
                $this->metric('Value completed', $this->peso((int) $completed->sum(fn (Order $order): int => $order->totalInCentavos()))),
            ],
            'tables' => [
                $this->table('By status', ['Status', 'Orders', 'Value'], $byStatus, 'No orders.'),
                $this->table(
                    'Orders',
                    ['Reference', 'Customer', 'Pickup', 'Outlet', 'Status', 'Total'],
                    $orders->map(fn (Order $order): array => [
                        $order->formattedReference(),
                        $order->customer_name,
                        $order->pickup_at->format('d M Y g:i A'),
                        $order->outlet->name,
                        $order->status->label(),
                        $this->peso($order->totalInCentavos()),
                    ])->all(),
                    'No orders are due for pickup in this period.',
                ),
            ],
        ];
    }

    /**
     * Takings over the range, the same figures as the sales report.
     *
     * @return Summary
     */
    private function sales(ReportRange $range): array
    {
        $from = $range->from();
        $to = $range->to();

        $takings = Sale::takingsInCentavos($from, $to);
        $count = Sale::countCompleted($from, $to);
        $daily = Sale::dailyTakings($from, $to);

        return [
            'metrics' => [
                $this->metric('Takings', $this->peso($takings)),
                $this->metric('Sales', number_format($count)),
                $this->metric('Units sold', number_format((int) $daily->sum('units'))),
                $this->metric('Average sale', $this->peso($count === 0 ? 0 : (int) round($takings / $count))),
            ],
            'tables' => [
                $this->table(
                    'Day by day',
                    ['Day', 'Sales', 'Units', 'Takings'],
                    $daily->map(fn (array $row): array => [
                        $row['day'],
                        number_format($row['sales']),
                        number_format($row['units']),
                        $this->peso($row['takings']),
                    ])->all(),
                    'Nothing was rung up in this period.',
                ),
                $this->table(
                    'By product',
                    ['Product', 'Size', 'Units', 'Takings'],
                    Sale::takingsByProduct($from, $to)->map(fn (array $row): array => [
                        $row['product'],
                        $row['size'],
                        number_format($row['units']),
                        $this->peso($row['takings']),
                    ])->all(),
                    'Nothing was sold in this period.',
                ),
                $this->table(
                    'By outlet',
                    ['Outlet', 'Sales', 'Units', 'Takings'],
                    Sale::takingsByOutlet($from, $to)->map(fn (array $row): array => [
                        $row['name'],
                        number_format($row['sales']),
                        number_format($row['units']),
                        $this->peso($row['takings']),
                    ])->all(),
                    'No outlet made a sale in this period.',
                ),
            ],
        ];
    }

    /**
     * Spending over the range, by heading and by location, then entry by
     * entry.
     *
     * @return Summary
     */
    private function expenses(ReportRange $range): array
    {
        $from = $range->from();
        $to = $range->to();

        $byCategory = Expense::totalsByCategory($from, $to);

        $entries = Expense::query()
            ->with(['category', 'outlet'])
            ->spentBetween($from, $to)
            ->orderBy('spent_on')
            ->orderBy('id')
            ->get();

        return [
            'metrics' => [
                $this->metric('Total spent', $this->peso(Expense::totalSpentInCentavos($from, $to))),
                $this->metric('Entries', number_format((int) $byCategory->sum('entries'))),
            ],
            'tables' => [
                $this->table(
                    'By category',
                    ['Category', 'Entries', 'Total'],
                    $byCategory->map(fn (array $row): array => [
                        $row['name'],
                        number_format($row['entries']),
                        $this->peso($row['total']),
                    ])->all(),
                    'Nothing was spent in this period.',
                ),
                $this->table(
                    'By outlet',
                    ['Outlet', 'Entries', 'Total'],
                    Expense::totalsByOutlet($from, $to)->map(fn (array $row): array => [
                        $row['name'],
                        number_format($row['entries']),
                        $this->peso($row['total']),
                    ])->all(),
                    'Nothing was spent in this period.',
                ),
                $this->table(
                    'Entries',
                    ['Date', 'Category', 'Outlet', 'Description', 'Amount'],
                    $entries->map(fn (Expense $expense): array => [
                        $expense->spent_on->format('d M Y'),
                        $expense->category->name,
                        $expense->outlet->name,
                        $expense->description,
                        $this->peso($expense->amountInCentavos()),
                    ])->all(),
                    'No expense was filed in this period.',
                ),
            ],
        ];
    }

    /**
     * Stock as it stands, and what moved over the range.
     *
     * Current stock is a standing figure whatever the range — the same rule
     * the inventory report keeps.
     *
     * @return Summary
     */
    private function inventory(ReportRange $range): array
    {
        $stocked = $this->stockedIngredients();
        $movement = InventoryMovement::summaryByIngredient($range->from(), $range->to());

        return [
            'metrics' => [
                $this->metric('Ingredients tracked', number_format($stocked->count())),
                $this->metric('Low or out (now)', number_format($this->lowIngredients()->count())),
                $this->metric('Stock entries', number_format((int) $movement->sum('entries'))),
            ],
            'tables' => [
                $this->table(
                    'Current stock',
                    ['Ingredient', 'In stock', 'Reorder at', 'Status'],
                    $stocked->map(fn (Ingredient $ingredient): array => [
                        $ingredient->name,
                        $ingredient->stock().' '.$ingredient->unit->abbreviation(),
                        $ingredient->reorderLevel().' '.$ingredient->unit->abbreviation(),
                        match (true) {
                            $ingredient->isOutOfStock() => 'Out of stock',
                            $ingredient->isLowStock() => 'Low',
                            default => 'OK',
                        },
                    ])->values()->all(),
                    'No ingredient has been added to the stockroom.',
                ),
                $this->table(
                    'Movement in this period',
                    ['Ingredient', 'Received', 'Used', 'Adjusted', 'Net'],
                    $movement->map(function (array $row): array {
                        $unit = ' '.$row['unit']->abbreviation();
                        $net = $row['received'] + $row['used'] + $row['adjusted'];

                        return [
                            $row['name'],
                            static::formatQuantity($row['received']).$unit,
                            static::formatQuantity(abs($row['used'])).$unit,
                            ($row['adjusted'] >= 0 ? '+' : '−').static::formatQuantity(abs($row['adjusted'])).$unit,
                            ($net >= 0 ? '+' : '−').static::formatQuantity(abs($net)).$unit,
                        ];
                    })->all(),
                    'Nothing moved through the stockroom in this period.',
                ),
            ],
        ];
    }

    /**
     * Restocks scheduled over the range, and what actually arrived.
     *
     * @return Summary
     */
    private function restocking(ReportRange $range): array
    {
        $restocks = Restock::query()
            ->with(['outlet', 'items'])
            ->whereDate('scheduled_for', '>=', $range->from())
            ->whereDate('scheduled_for', '<=', $range->to())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();

        return [
            'metrics' => [
                $this->metric('Scheduled', number_format($restocks->count())),
                $this->metric('Delivered', number_format($restocks->where('status', RestockStatus::Delivered)->count())),
                $this->metric('Still open', number_format($restocks->reject(fn (Restock $restock): bool => $restock->status->isTerminal())->count())),
                $this->metric('Cancelled', number_format($restocks->where('status', RestockStatus::Cancelled)->count())),
            ],
            'tables' => [
                $this->table(
                    'Scheduled in this period',
                    ['Reference', 'Outlet', 'Scheduled for', 'Status', 'Requested', 'Prepared'],
                    $restocks->map(fn (Restock $restock): array => [
                        strtoupper($restock->reference),
                        $restock->outlet->name,
                        $restock->scheduled_for->format('d M Y'),
                        $restock->status->label(),
                        number_format($restock->requestedUnits()),
                        $restock->isPrepared() ? number_format($restock->preparedUnits()) : '—',
                    ])->all(),
                    'No restock is scheduled in this period.',
                ),
                $this->table(
                    'Delivered in this period, by outlet',
                    ['Outlet', 'Restocks', 'Units received'],
                    Restock::deliveriesByOutlet($range->from(), $range->to())->map(fn (array $row): array => [
                        $row['name'],
                        number_format($row['restocks']),
                        number_format($row['units']),
                    ])->all(),
                    'Nothing was delivered in this period.',
                ),
            ],
        ];
    }

    /**
     * Output over the range, the same figures as the production report.
     *
     * @return Summary
     */
    private function production(ReportRange $range): array
    {
        $from = $range->from();
        $to = $range->to();

        $units = ProductionRun::unitsProduced($from, $to);
        $runs = ProductionRun::runsLogged($from, $to);

        return [
            'metrics' => [
                $this->metric('Units produced', number_format($units)),
                $this->metric('Runs logged', number_format($runs)),
                $this->metric('Average run', number_format($runs === 0 ? 0 : (int) round($units / $runs)).' units'),
            ],
            'tables' => [
                $this->table(
                    'Day by day',
                    ['Day', 'Runs', 'Units'],
                    ProductionRun::dailyOutput($from, $to)->map(fn (array $row): array => [
                        $row['day'],
                        number_format($row['runs']),
                        number_format($row['units']),
                    ])->all(),
                    'Nothing came out of the oven in this period.',
                ),
                $this->table(
                    'By product',
                    ['Product', 'Size', 'Runs', 'Units'],
                    ProductionRun::outputByVariant($from, $to)->map(fn (array $row): array => [
                        $row['product'],
                        $row['size'],
                        number_format($row['runs']),
                        number_format($row['units']),
                    ])->all(),
                    'Nothing was produced in this period.',
                ),
            ],
        ];
    }

    /**
     * The roster over the range, and each employee's share of it and of the
     * record — the workforce report's rows, added up the same way.
     *
     * @return Summary
     */
    private function workforce(ReportRange $range): array
    {
        $roster = Shift::query()
            ->with(['outlet', 'assignments.user'])
            ->startingBetween($range->from(), $range->to())
            ->orderBy('starts_at')
            ->get();

        $rostered = [];

        foreach ($roster as $shift) {
            foreach ($shift->assignments as $assignment) {
                $rostered[$assignment->user_id] ??= ['shifts' => 0, 'minutes' => 0];
                $rostered[$assignment->user_id]['shifts']++;
                $rostered[$assignment->user_id]['minutes'] += $shift->durationInMinutes();
            }
        }

        $employees = User::recordedBetween($range->from(), $range->to())
            ->map(fn (array $row): array => $row + [
                'shifts' => $rostered[$row['id']]['shifts'] ?? 0,
                'minutes' => $rostered[$row['id']]['minutes'] ?? 0,
            ])
            ->filter(fn (array $row): bool => $row['is_active'] || $row['total'] > 0 || $row['shifts'] > 0);

        return [
            'metrics' => [
                $this->metric('Shifts', number_format($roster->count())),
                $this->metric('Places filled', number_format((int) $roster->sum(fn (Shift $shift): int => $shift->assignments->count()))),
                $this->metric('Rostered hours', Shift::formatMinutes((int) collect($rostered)->sum('minutes'))),
            ],
            'tables' => [
                $this->table(
                    'By employee',
                    ['Employee', 'Role', 'Shifts', 'Hours', 'Sales', 'Runs', 'Stock entries', 'Expenses'],
                    $employees->map(fn (array $row): array => [
                        $row['name'],
                        $row['role']->label(),
                        number_format($row['shifts']),
                        Shift::formatMinutes($row['minutes']),
                        number_format($row['sales']),
                        number_format($row['runs']),
                        number_format($row['movements']),
                        number_format($row['expenses']),
                    ])->values()->all(),
                    'No employee on record.',
                ),
                $this->table(
                    'Roster',
                    ['Day', 'Shift', 'Outlet', 'Time', 'Staff'],
                    $roster->map(fn (Shift $shift): array => [
                        $shift->starts_at->format('D d M'),
                        $shift->name,
                        $shift->outlet->name,
                        $shift->starts_at->format('g:i A').' – '.$shift->ends_at->format('g:i A'),
                        $shift->assignments->map(fn ($assignment): string => $assignment->user->name)->join(', ') ?: '—',
                    ])->all(),
                    'No shift is rostered in this period.',
                ),
            ],
        ];
    }

    /**
     * Every location side by side, main branch first — the outlet
     * performance report's rows.
     *
     * @return Summary
     */
    private function outlets(ReportRange $range): array
    {
        $from = $range->from();
        $to = $range->to();

        $takings = Sale::takingsByOutlet($from, $to)->keyBy('outlet_id');
        $deliveries = Restock::deliveriesByOutlet($from, $to)->keyBy('outlet_id');
        $spending = Expense::totalsByOutlet($from, $to)->keyBy('outlet_id');

        $rows = Outlet::query()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get()
            ->map(fn (Outlet $outlet): array => [
                'name' => $outlet->name,
                'sales' => $takings->get($outlet->id)['sales'] ?? 0,
                'takings' => $takings->get($outlet->id)['takings'] ?? 0,
                'restocks' => $deliveries->get($outlet->id)['restocks'] ?? 0,
                'received' => $deliveries->get($outlet->id)['units'] ?? 0,
                'spent' => $spending->get($outlet->id)['total'] ?? 0,
            ]);

        $totalTakings = (int) $rows->sum('takings');
        $totalSpent = (int) $rows->sum('spent');

        return [
            'metrics' => [
                $this->metric('Outlets', number_format($rows->count())),
                $this->metric('Takings', $this->peso($totalTakings)),
                $this->metric('Spent', $this->peso($totalSpent)),
                $this->metric('Takings less spending', $this->peso($totalTakings - $totalSpent)),
            ],
            'tables' => [
                $this->table(
                    'By outlet',
                    ['Outlet', 'Sales', 'Takings', 'Restocks', 'Units received', 'Spent', 'Net'],
                    $rows->map(fn (array $row): array => [
                        $row['name'],
                        number_format($row['sales']),
                        $this->peso($row['takings']),
                        number_format($row['restocks']),
                        number_format($row['received']),
                        $this->peso($row['spent']),
                        $this->peso($row['takings'] - $row['spent']),
                    ])->all(),
                    'No outlet has been added.',
                ),
            ],
        ];
    }

    /**
     * The employee's own shifts over the range, and nobody else's (BR-012).
     *
     * @return Summary
     */
    private function schedule(ReportRange $range, User $user): array
    {
        $shifts = $this->ownShifts($range, $user);

        return [
            'metrics' => [
                $this->metric('Shifts', number_format($shifts->count())),
                $this->metric('Hours', Shift::formatMinutes((int) $shifts->sum(fn (Shift $shift): int => $shift->durationInMinutes()))),
            ],
            'tables' => [
                $this->table(
                    'Shifts',
                    ['Day', 'Shift', 'Outlet', 'Time', 'Length'],
                    $shifts->map(fn (Shift $shift): array => [
                        $shift->starts_at->format('D d M Y'),
                        $shift->name,
                        $shift->outlet->name,
                        $shift->starts_at->format('g:i A').' – '.$shift->ends_at->format('g:i A'),
                        $shift->duration(),
                    ])->all(),
                    'You have no shift in this period.',
                ),
            ],
        ];
    }

    /**
     * The employee's own shifts starting over the range, earliest first.
     *
     * @return Collection<int, Shift>
     */
    private function ownShifts(ReportRange $range, User $user): Collection
    {
        return Shift::query()
            ->with('outlet')
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id))
            ->startingBetween($range->from(), $range->to())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Active ingredients with their running stock, by name.
     *
     * @return Collection<int, Ingredient>
     */
    private function stockedIngredients(): Collection
    {
        return Ingredient::query()->active()->withStock()->orderBy('name')->get();
    }

    /**
     * The ingredients that need ordering, as the inventory report counts them.
     *
     * @return Collection<int, Ingredient>
     */
    private function lowIngredients(): Collection
    {
        return $this->stockedIngredients()->filter(
            fn (Ingredient $ingredient): bool => $ingredient->isLowStock() || $ingredient->isOutOfStock(),
        );
    }

    /**
     * One headline figure.
     *
     * @return Metric
     */
    private function metric(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /**
     * One table, with the line printed in its place when it has no rows.
     *
     * @param  list<string>  $columns
     * @param  array<int, list<string>>  $rows
     * @return Table
     */
    private function table(string $heading, array $columns, array $rows, string $empty): array
    {
        return ['heading' => $heading, 'columns' => $columns, 'rows' => array_values($rows), 'empty' => $empty];
    }

    /**
     * Centavos as pesos.
     */
    private function peso(int $centavos): string
    {
        return ($centavos < 0 ? '−' : '').'₱'.number_format(abs($centavos) / 100, 2);
    }
}
