<?php

use App\Concerns\ReportRange;
use App\Models\Outlet;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales report')] class extends Component {
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
        Gate::authorize('access-sales');

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
     * The locations sales can belong to.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->orderByDesc('is_main_branch')->orderBy('name')->get();
    }

    /**
     * Takings over the range, in centavos.
     */
    #[Computed]
    public function takings(): int
    {
        return Sale::takingsInCentavos($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * Takings over the stretch of equal length before it.
     */
    #[Computed]
    public function previousTakings(): int
    {
        return Sale::takingsInCentavos($this->range->previousFrom(), $this->range->previousTo(), $this->outletFilter());
    }

    /**
     * How many sales were rung up.
     */
    #[Computed]
    public function salesCount(): int
    {
        return Sale::countCompleted($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * Day by day, which is both the daily figure and the trend.
     *
     * @return Collection<int, array{day: string, sales: int, units: int, takings: int}>
     */
    #[Computed]
    public function daily(): Collection
    {
        return Sale::dailyTakings($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * The busiest day's takings, so the bars have something to scale against.
     */
    #[Computed]
    public function busiestDay(): int
    {
        return (int) $this->daily->max('takings');
    }

    /**
     * How many units left the counter.
     */
    #[Computed]
    public function units(): int
    {
        return (int) $this->daily->sum('units');
    }

    /**
     * What the average sale came to, in centavos.
     */
    #[Computed]
    public function averageSale(): int
    {
        return $this->salesCount === 0 ? 0 : (int) round($this->takings / $this->salesCount);
    }

    /**
     * What sold.
     *
     * @return Collection<int, array{product: string, size: string, units: int, takings: int}>
     */
    #[Computed]
    public function byProduct(): Collection
    {
        return Sale::takingsByProduct($this->range->from(), $this->range->to(), $this->outletFilter());
    }

    /**
     * Where it sold. Never narrowed — this table is the comparison, so showing
     * one outlet in it would answer a question nobody asked.
     *
     * @return Collection<int, array{outlet_id: int, name: string, sales: int, units: int, takings: int}>
     */
    #[Computed]
    public function byOutlet(): Collection
    {
        return Sale::takingsByOutlet($this->range->from(), $this->range->to());
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sales report') }}</flux:heading>
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

    @php($change = $this->range->change($this->takings, $this->previousTakings))

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Takings') }}</span>
            <p class="mt-1 text-3xl font-semibold">&#8369;{{ number_format($this->takings / 100, 2) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                @if ($change === null)
                    {{ __('Nothing in the :count days before, to compare against', ['count' => $this->range->days()]) }}
                @else
                    <span class="{{ $change < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ $change >= 0 ? '+' : '' }}{{ number_format($change, 1) }}%
                    </span>
                    {{ __('against the :count days before', ['count' => $this->range->days()]) }}
                @endif
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Sales') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->salesCount) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Completed, voided ones excluded') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Units sold') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->units) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Across every line on every sale') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Average sale') }}</span>
            <p class="mt-1 text-3xl font-semibold">&#8369;{{ number_format($this->averageSale / 100, 2) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Takings over the number of sales') }}</p>
        </flux:card>
    </div>

    @if ($this->daily->isEmpty())
        <flux:callout icon="banknotes" class="mt-6">
            <flux:callout.heading>{{ __('No sales in this range') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Nothing was rung up on these days at this location.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:heading size="lg" class="mt-8">{{ __('Day by day') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Day') }}</flux:table.column>
                    <flux:table.column>{{ __('Sales') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                    <flux:table.column>{{ __('Takings') }}</flux:table.column>
                    <flux:table.column class="w-1/3">{{ __('Share of the busiest day') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->daily as $row)
                        <flux:table.row wire:key="day-{{ $row['day'] }}">
                            <flux:table.cell class="font-medium">{{ $row['day'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['sales']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format($row['takings'] / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                                    <div
                                        class="h-2 rounded-full bg-purple-500"
                                        style="width: {{ $this->busiestDay > 0 ? round($row['takings'] / $this->busiestDay * 100, 1) : 0 }}%"
                                    ></div>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:heading size="lg" class="mt-8">{{ __('By product') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                    <flux:table.column>{{ __('Takings') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->byProduct as $row)
                        <flux:table.row wire:key="product-{{ $loop->index }}">
                            <flux:table.cell class="font-medium">{{ $row['product'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['size'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format($row['takings'] / 100, 2) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:heading size="lg" class="mt-8">{{ __('By outlet') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every location over the same days, whatever the filter above is set to.') }}</flux:text>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Sales') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                    <flux:table.column>{{ __('Takings') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->byOutlet as $row)
                        <flux:table.row wire:key="outlet-{{ $row['outlet_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['sales']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format($row['takings'] / 100, 2) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
