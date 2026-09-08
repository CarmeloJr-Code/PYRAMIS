<?php

use App\Concerns\ReportRange;
use App\Models\Expense;
use App\Models\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Expense report')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'outlet', except: 0)]
    public int $outletId = 0;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-expenses');

        // The month so far is what a report is usually opened to see.
        [$month, $today] = ReportRange::currentMonth();

        $this->from = $this->from ?: $month;
        $this->to = $this->to ?: $today;
    }

    /**
     * The stretch of days this report covers.
     */
    #[Computed]
    public function range(): ReportRange
    {
        return ReportRange::between($this->from, $this->to);
    }

    /**
     * Jump to one of the stretches a manager asks for by name.
     */
    public function setRange(string $preset): void
    {
        [$this->from, $this->to] = ReportRange::preset($preset);

        unset($this->range);
    }

    /**
     * The outlet the report is narrowed to, or null for everywhere.
     */
    public function outletFilter(): ?int
    {
        return $this->outletId > 0 ? $this->outletId : null;
    }

    /**
     * The locations expenses can belong to.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->orderByDesc('is_main_branch')->orderBy('name')->get();
    }

    /**
     * What was spent over the range, in centavos.
     */
    #[Computed]
    public function total(): int
    {
        return Expense::totalSpentInCentavos($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * What was spent over the stretch of equal length before it.
     */
    #[Computed]
    public function previousTotal(): int
    {
        return Expense::totalSpentInCentavos($this->range->previousFrom(), $this->range->previousTo(), $this->outletFilter());
    }

    /**
     * Spending by heading.
     *
     * @return Collection<int, array{name: string, entries: int, total: int}>
     */
    #[Computed]
    public function byCategory(): Collection
    {
        return Expense::totalsByCategory($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * Spending by location. Never narrowed — this table is the comparison.
     *
     * @return Collection<int, array{outlet_id: int, name: string, entries: int, total: int}>
     */
    #[Computed]
    public function byOutlet(): Collection
    {
        return Expense::totalsByOutlet($this->range->from(), $this->range->to());
    }

    /**
     * How many expenses were filed over the range.
     */
    #[Computed]
    public function entries(): int
    {
        return (int) $this->byCategory->sum('entries');
    }

    /**
     * The biggest heading, so the bars have something to scale against.
     */
    #[Computed]
    public function largestCategory(): int
    {
        return (int) $this->byCategory->max('total');
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Expense report') }}</flux:heading>
            <flux:text class="mt-2">{{ $this->range->label() }}</flux:text>
        </div>

        <flux:button :href="route('employee.reports.index')" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('All reports') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <x-report-range>
        <flux:select wire:model.live="outletId" :label="__('Location')" class="max-w-56">
            <flux:select.option value="0">{{ __('Everywhere') }}</flux:select.option>
            @foreach ($this->outlets as $outlet)
                <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-report-range>

    @php($change = $this->range->change($this->total, $this->previousTotal))

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Spent') }}</span>
            <p class="mt-1 text-3xl font-semibold">&#8369;{{ number_format($this->total / 100, 2) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                @if ($change === null)
                    {{ __('Nothing in the :count days before, to compare against', ['count' => $this->range->days()]) }}
                @else
                    {{-- Spending up is not good news, so the colours run the other way from sales. --}}
                    <span class="{{ $change > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ $change >= 0 ? '+' : '' }}{{ number_format($change, 1) }}%
                    </span>
                    {{ __('against the :count days before', ['count' => $this->range->days()]) }}
                @endif
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Expenses filed') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->entries) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Individual entries') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Headings used') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->byCategory->count()) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Categories with something against them') }}</p>
        </flux:card>
    </div>

    @if ($this->byCategory->isEmpty())
        <flux:callout icon="receipt-percent" class="mt-6">
            <flux:callout.heading>{{ __('Nothing spent in this range') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No expense was filed on these days at this location.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:heading size="lg" class="mt-8">{{ __('By category') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Entries') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Share') }}</flux:table.column>
                    <flux:table.column class="w-1/4" />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->byCategory as $row)
                        <flux:table.row wire:key="cat-{{ $loop->index }}">
                            <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['entries']) }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format($row['total'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $this->total > 0 ? number_format($row['total'] / $this->total * 100, 1) : '0.0' }}%
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                                    <div
                                        class="h-2 rounded-full bg-purple-500"
                                        style="width: {{ $this->largestCategory > 0 ? round($row['total'] / $this->largestCategory * 100, 1) : 0 }}%"
                                    ></div>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:heading size="lg" class="mt-8">{{ __('By location') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every location over the same days, whatever the filter above is set to.') }}</flux:text>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('Entries') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->byOutlet as $row)
                        <flux:table.row wire:key="outlet-{{ $row['outlet_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['entries']) }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format($row['total'] / 100, 2) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
