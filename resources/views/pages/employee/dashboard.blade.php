<?php

use App\Actions\CountWorkNeedingAttention;
use App\Concerns\ReportRange;
use App\Enums\OrderStatus;
use App\Enums\RestockStatus;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductionRun;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * How many days the trend lines read back, today included: today plus two
     * full weeks, so the last full week can be set against the one before it.
     */
    private const TREND_DAYS = 15;

    /**
     * How many rows each of today's lists shows before pointing at the screen
     * that holds the rest.
     */
    private const LIST_LENGTH = 5;

    /**
     * The signed-in employee.
     */
    #[Computed]
    public function employee(): User
    {
        return Auth::user();
    }

    /**
     * Today, as every figure here reads it.
     */
    #[Computed]
    public function today(): string
    {
        return now()->toDateString();
    }

    /**
     * What is waiting on the employee, section by section — the same figures as
     * the sidebar's badges, counted once for both.
     *
     * @return list<array{label: string, href: string, icon: string}>
     */
    #[Computed]
    public function attention(): array
    {
        $counts = app(CountWorkNeedingAttention::class)->handle($this->employee);

        $links = [
            'orders' => fn (int $count): array => [
                'label' => trans_choice(':count payment to check|:count payments to check', $count),
                'href' => route('employee.orders.index', ['status' => OrderStatus::Pending->value]),
                'icon' => 'banknotes',
            ],
            'inventory' => fn (int $count): array => [
                'label' => trans_choice(':count ingredient low|:count ingredients low', $count),
                'href' => route('employee.inventory.index'),
                'icon' => 'exclamation-triangle',
            ],
            'restocks' => fn (int $count): array => [
                'label' => trans_choice(':count restock to prepare|:count restocks to prepare', $count),
                'href' => route('employee.restocks.index'),
                'icon' => 'truck',
            ],
            'messages' => fn (int $count): array => [
                'label' => trans_choice(':count unread message|:count unread messages', $count),
                'href' => route('employee.messages.index'),
                'icon' => 'chat-bubble-left-right',
            ],
        ];

        $attention = [];

        foreach ($links as $section => $link) {
            if (isset($counts[$section])) {
                $attention[] = $link($counts[$section]);
            }
        }

        return $attention;
    }

    /**
     * Takings day by day, oldest first, in centavos — one value a day, quiet
     * days included as zero.
     *
     * @return list<int>
     */
    #[Computed]
    public function salesSeries(): array
    {
        $daily = Sale::dailyTakings($this->trendStart(), $this->today)->pluck('takings', 'day');

        return $this->fillDays($daily->all());
    }

    /**
     * Takings so far today, formatted.
     */
    #[Computed]
    public function salesToday(): string
    {
        return number_format(last($this->salesSeries) / 100, 2);
    }

    /**
     * The last full week of takings, against the week before it.
     *
     * @return array{total: int, change: float|null}
     */
    #[Computed]
    public function salesWeek(): array
    {
        return $this->week($this->salesSeries);
    }

    /**
     * Takings so far this month, formatted.
     */
    #[Computed]
    public function salesThisMonth(): string
    {
        return number_format(
            Sale::takingsInCentavos(now()->startOfMonth()->toDateString(), $this->today) / 100,
            2,
        );
    }

    /**
     * Spending so far this month, formatted.
     */
    #[Computed]
    public function expensesThisMonth(): string
    {
        return number_format(
            Expense::totalSpentInCentavos(now()->startOfMonth()->toDateString(), $this->today) / 100,
            2,
        );
    }

    /**
     * Orders still to be dealt with.
     */
    #[Computed]
    public function openOrders(): int
    {
        return Order::query()->open()->count();
    }

    /**
     * Open orders due to be collected today, soonest first.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function pickupsToday(): Collection
    {
        return $this->pickupsTodayQuery()
            ->with('outlet')
            ->orderBy('pickup_at')
            ->orderBy('id')
            ->limit(self::LIST_LENGTH)
            ->get();
    }

    /**
     * How many open orders are due today, the ones listed or not.
     */
    #[Computed]
    public function pickupsTodayCount(): int
    {
        return $this->pickupsTodayQuery()->count();
    }

    /**
     * Units out of the oven day by day, oldest first.
     *
     * @return list<int>
     */
    #[Computed]
    public function outputSeries(): array
    {
        $daily = ProductionRun::dailyOutput($this->trendStart(), $this->today)->pluck('units', 'day');

        return $this->fillDays($daily->all());
    }

    /**
     * Units out of the oven today.
     */
    #[Computed]
    public function producedToday(): int
    {
        return last($this->outputSeries);
    }

    /**
     * The last full week of output, against the week before it.
     *
     * @return array{total: int, change: float|null}
     */
    #[Computed]
    public function outputWeek(): array
    {
        return $this->week($this->outputSeries);
    }

    /**
     * Ingredients at or below the level the business set, by name.
     *
     * Filtered in PHP over the stocked ingredients, because "low" compares a
     * summed ledger against a per-row threshold — a short list, read once.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function lowStockIngredients(): Collection
    {
        return Ingredient::query()
            ->active()
            ->withStock()
            ->orderBy('name')
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->isLowStock())
            ->values();
    }

    /**
     * How many ingredients are low.
     */
    #[Computed]
    public function lowStockCount(): int
    {
        return $this->lowStockIngredients->count();
    }

    /**
     * Restocks scheduled but not yet delivered.
     */
    #[Computed]
    public function openRestocks(): int
    {
        return Restock::query()->open()->count();
    }

    /**
     * Restocks nobody has started on, the soonest due first.
     *
     * @return Collection<int, Restock>
     */
    #[Computed]
    public function restocksToPrepare(): Collection
    {
        return Restock::query()
            ->where('status', RestockStatus::Requested)
            ->with('outlet')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit(self::LIST_LENGTH)
            ->get();
    }

    /**
     * Outlets currently open.
     */
    #[Computed]
    public function activeOutlets(): int
    {
        return Outlet::query()->active()->count();
    }

    /**
     * Assignments on today's roster.
     */
    #[Computed]
    public function onShiftToday(): int
    {
        return (int) Shift::query()
            ->startingOn($this->today)
            ->withCount('assignments')
            ->get()
            ->sum('assignments_count');
    }

    /**
     * The signed-in employee's next shift, whatever their role.
     */
    #[Computed]
    public function nextShift(): ?Shift
    {
        return Shift::query()
            ->with('outlet')
            ->whereHas('assignments', fn ($query) => $query->where('user_id', Auth::id()))
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Messages waiting for the signed-in employee.
     */
    #[Computed]
    public function unreadMessages(): int
    {
        return app(CountWorkNeedingAttention::class)->handle($this->employee)['messages'] ?? 0;
    }

    /**
     * The first day the trend lines read.
     */
    private function trendStart(): string
    {
        return now()->subDays(self::TREND_DAYS - 1)->toDateString();
    }

    /**
     * One value for each day of the trend, zero where nothing was recorded.
     *
     * @param  array<string, int>  $byDay
     * @return list<int>
     */
    private function fillDays(array $byDay): array
    {
        $days = [];

        for ($back = self::TREND_DAYS - 1; $back >= 0; $back--) {
            $days[] = (int) ($byDay[now()->subDays($back)->toDateString()] ?? 0);
        }

        return $days;
    }

    /**
     * The seven full days to yesterday, against the seven before them.
     *
     * Today is left out of both: half a day set against whole ones would read
     * as a fall every morning.
     *
     * @param  list<int>  $series
     * @return array{total: int, change: float|null}
     */
    private function week(array $series): array
    {
        $before = array_sum(array_slice($series, 0, 7));
        $total = array_sum(array_slice($series, 7, 7));

        $range = ReportRange::between(now()->subDays(7)->toDateString(), now()->subDay()->toDateString());

        return ['total' => $total, 'change' => $range->change($total, $before)];
    }

    /**
     * Open orders due to be collected today.
     *
     * @return Builder<Order>
     */
    private function pickupsTodayQuery(): Builder
    {
        return Order::query()->open()->whereDate('pickup_at', $this->today);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $this->employee->name]) }}</flux:heading>

        <div class="flex items-center gap-2">
            <flux:badge size="sm" color="purple" data-test="role-badge">{{ $this->employee->role->label() }}</flux:badge>
            <flux:text>{{ __('Purple Yam Malaybalay') }}</flux:text>
        </div>
    </div>

    {{-- What to pick up next, before any figure: each one opens where it is done. --}}
    <div class="mt-6 flex flex-wrap items-center gap-2" data-test="needs-attention">
        <flux:heading size="sm" class="me-1">{{ __('Needs attention') }}</flux:heading>

        @forelse ($this->attention as $item)
            <flux:button size="sm" :icon="$item['icon']" :href="$item['href']" wire:navigate>{{ $item['label'] }}</flux:button>
        @empty
            <flux:text>{{ __('All caught up.') }}</flux:text>
        @endforelse
    </div>

    <flux:separator variant="subtle" class="my-6" />

    {{--
        Each role is shown the figures it acts on. The capability matrix gives
        the Administrator the whole business dashboard and the other two a
        limited one, so a Cashier sees the counter and a Baker sees the kitchen
        rather than everybody seeing everything (BR-012). Each figure opens the
        screen it was counted from.
    --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @can('access-sales')
            <x-dashboard-tile :href="route('employee.sales.index')" :label="__('Sales today')">
                <p class="mt-1 text-3xl font-semibold">&#8369;{{ $this->salesToday }}</p>
                @include('partials.dashboard-week', [
                    'week' => $this->salesWeek,
                    'total' => '₱'.number_format($this->salesWeek['total'] / 100, 2),
                ])
                <x-sparkline :points="$this->salesSeries" class="mt-3" />
                <p class="mt-2 text-xs text-zinc-500">
                    {{ __(':amount this month', ['amount' => '₱'.$this->salesThisMonth]) }}
                </p>
            </x-dashboard-tile>
        @endcan

        @can('manage-orders')
            <x-dashboard-tile :href="route('employee.orders.index')" :label="__('Orders waiting')">
                <p class="mt-1 text-3xl font-semibold">{{ $this->openOrders }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Pre-orders not yet collected or cancelled') }}</p>
            </x-dashboard-tile>
        @endcan

        @can('access-expenses')
            <x-dashboard-tile :href="route('employee.expenses.index')" :label="__('Expenses this month')">
                <p class="mt-1 text-3xl font-semibold">&#8369;{{ $this->expensesThisMonth }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Recorded across every location') }}</p>
            </x-dashboard-tile>
        @endcan

        @can('access-production')
            <x-dashboard-tile :href="route('employee.production.runs.index')" :label="__('Produced today')">
                <p class="mt-1 text-3xl font-semibold">{{ $this->producedToday }}</p>
                @include('partials.dashboard-week', [
                    'week' => $this->outputWeek,
                    'total' => trans_choice(':count unit|:count units', $this->outputWeek['total']),
                ])
                <x-sparkline :points="$this->outputSeries" class="mt-3" />
            </x-dashboard-tile>
        @endcan

        @can('access-inventory')
            <x-dashboard-tile :href="route('employee.inventory.index')" :label="__('Low on stock')">
                <p class="mt-1 text-3xl font-semibold {{ $this->lowStockCount > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                    {{ $this->lowStockCount }}
                </p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Ingredients at or below their reorder level') }}</p>
            </x-dashboard-tile>
        @endcan

        @can('access-restocking')
            <x-dashboard-tile :href="route('employee.restocks.index')" :label="__('Restocks open')">
                <p class="mt-1 text-3xl font-semibold">{{ $this->openRestocks }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Scheduled but not yet delivered') }}</p>
            </x-dashboard-tile>
        @endcan

        @can('access-workforce')
            <x-dashboard-tile :href="route('employee.workforce')" :label="__('On shift today')">
                <p class="mt-1 text-3xl font-semibold">{{ $this->onShiftToday }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Assignments on today\'s roster') }}</p>
            </x-dashboard-tile>
        @endcan

        @can('manage-outlets')
            <x-dashboard-tile :href="route('employee.outlets.index')" :label="__('Outlets open')">
                <p class="mt-1 text-3xl font-semibold">{{ $this->activeOutlets }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Taking pickups and sales') }}</p>
            </x-dashboard-tile>
        @endcan

        {{-- Everyone's own two: what they work next, and who is waiting on them. --}}
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Your next shift') }}</span>
            @if ($this->nextShift)
                <p class="mt-1 text-xl font-semibold">{{ $this->nextShift->name }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ $this->nextShift->starts_at->format('D d M, g:ia') }} &middot; {{ $this->nextShift->outlet->name }}
                </p>
            @else
                <p class="mt-1 text-xl font-semibold text-zinc-400">{{ __('Nothing scheduled') }}</p>
            @endif
            @can('view-own-schedule')
                <flux:button :href="route('employee.schedule')" variant="ghost" size="sm" class="mt-3" wire:navigate>
                    {{ __('My schedule') }}
                </flux:button>
            @endcan
        </flux:card>

        <x-dashboard-tile :href="route('employee.messages.index')" :label="__('Unread messages')">
            <p class="mt-1 text-3xl font-semibold {{ $this->unreadMessages > 0 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                {{ $this->unreadMessages }}
            </p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Across every conversation you are in') }}</p>
        </x-dashboard-tile>
    </div>

    {{-- Today's work, by name: what the figures above are made of, for whoever does it. --}}
    @canany(['access-forecasting', 'manage-orders', 'access-restocking', 'access-inventory'])
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            @can('access-forecasting')
                <livewire:bake-next lazy />
            @endcan

            @can('manage-orders')
                <flux:card data-test="pickups-today">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading>{{ __('Pickups today') }}</flux:heading>
                        <flux:badge size="sm">{{ $this->pickupsTodayCount }}</flux:badge>
                    </div>

                    <div class="mt-3 grid gap-1">
                        @forelse ($this->pickupsToday as $order)
                            <a wire:key="pickup-{{ $order->id }}" href="{{ route('employee.orders.show', $order) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ $order->pickup_at->format('g:ia') }} &middot; {{ $order->customer_name }}</span>
                                    <span class="block truncate font-mono text-xs text-zinc-500">{{ $order->reference }} &middot; {{ $order->outlet->name }}</span>
                                </span>
                                <flux:badge size="sm" :color="$order->status === OrderStatus::Ready ? 'green' : null">{{ $order->status->label() }}</flux:badge>
                            </a>
                        @empty
                            <flux:text class="px-2 py-2">{{ __('No pickups due today.') }}</flux:text>
                        @endforelse
                    </div>

                    @if ($this->pickupsTodayCount > $this->pickupsToday->count())
                        <flux:button :href="route('employee.orders.index')" variant="ghost" size="sm" class="mt-2" wire:navigate>
                            {{ __(':count more in the order queue', ['count' => $this->pickupsTodayCount - $this->pickupsToday->count()]) }}
                        </flux:button>
                    @endif
                </flux:card>
            @endcan

            @can('access-restocking')
                <flux:card data-test="restocks-to-prepare">
                    <flux:heading>{{ __('Restocks to prepare') }}</flux:heading>

                    <div class="mt-3 grid gap-1">
                        @forelse ($this->restocksToPrepare as $restock)
                            <a wire:key="restock-{{ $restock->id }}" href="{{ route('employee.restocks.show', $restock) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ $restock->outlet->name }}</span>
                                    <span class="block truncate font-mono text-xs text-zinc-500">{{ $restock->reference }}</span>
                                </span>
                                <span class="shrink-0 text-sm text-zinc-500">{{ $restock->scheduled_for->format('D d M') }}</span>
                            </a>
                        @empty
                            <flux:text class="px-2 py-2">{{ __('Nothing waiting to be prepared.') }}</flux:text>
                        @endforelse
                    </div>
                </flux:card>
            @endcan

            @can('access-inventory')
                <flux:card data-test="low-ingredients">
                    <flux:heading>{{ __('Running low') }}</flux:heading>

                    <div class="mt-3 grid gap-1">
                        @forelse ($this->lowStockIngredients->take(5) as $ingredient)
                            <a wire:key="ingredient-{{ $ingredient->id }}" href="{{ route('employee.inventory.show', $ingredient) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                                <span class="truncate text-sm font-medium">{{ $ingredient->name }}</span>
                                <span class="shrink-0 text-sm text-amber-600 dark:text-amber-400">
                                    {{ __(':stock of :level :unit', [
                                        'stock' => $ingredient->stock(),
                                        'level' => $ingredient->reorderLevel(),
                                        'unit' => $ingredient->unit->abbreviation(),
                                    ]) }}
                                </span>
                            </a>
                        @empty
                            <flux:text class="px-2 py-2">{{ __('Everything is above its reorder level.') }}</flux:text>
                        @endforelse
                    </div>

                    @if ($this->lowStockCount > 5)
                        <flux:button :href="route('employee.inventory.index')" variant="ghost" size="sm" class="mt-2" wire:navigate>
                            {{ __(':count more in the stockroom', ['count' => $this->lowStockCount - 5]) }}
                        </flux:button>
                    @endif
                </flux:card>
            @endcan
        </div>
    @endcanany
</section>
