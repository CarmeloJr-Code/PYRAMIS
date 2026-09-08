<?php

use App\Concerns\ReportRange;
use App\Models\ProductionRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Production report')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-production');

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
     * Units out of the oven over the range.
     */
    #[Computed]
    public function units(): int
    {
        return ProductionRun::unitsProduced($this->range->from(), $this->range->to());
    }

    /**
     * Units over the stretch of equal length before it.
     */
    #[Computed]
    public function previousUnits(): int
    {
        return ProductionRun::unitsProduced($this->range->previousFrom(), $this->range->previousTo());
    }

    /**
     * How many runs were logged.
     */
    #[Computed]
    public function runs(): int
    {
        return ProductionRun::runsLogged($this->range->from(), $this->range->to());
    }

    /**
     * The average run size, in units.
     */
    #[Computed]
    public function averageRun(): int
    {
        return $this->runs === 0 ? 0 : (int) round($this->units / $this->runs);
    }

    /**
     * Output day by day — the production history.
     *
     * @return Collection<int, array{day: string, runs: int, units: int}>
     */
    #[Computed]
    public function daily(): Collection
    {
        return ProductionRun::dailyOutput($this->range->from(), $this->range->to());
    }

    /**
     * The biggest day's output, so the bars have something to scale against.
     */
    #[Computed]
    public function biggestDay(): int
    {
        return (int) $this->daily->max('units');
    }

    /**
     * What was made, by size.
     *
     * @return Collection<int, array{product: string, size: string, runs: int, units: int}>
     */
    #[Computed]
    public function byVariant(): Collection
    {
        return ProductionRun::outputByVariant($this->range->from(), $this->range->to());
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Production report') }}</flux:heading>
            <flux:text class="mt-2">{{ $this->range->label() }}</flux:text>
        </div>

        <flux:button :href="route('employee.reports.index')" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('All reports') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <x-report-range />

    @php($change = $this->range->change($this->units, $this->previousUnits))

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Units produced') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->units) }}</p>
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
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Runs logged') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->runs) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Each one drew its ingredients from the stockroom') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Average run') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->averageRun) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Units per run') }}</p>
        </flux:card>
    </div>

    @if ($this->daily->isEmpty())
        <flux:callout icon="fire" class="mt-6">
            <flux:callout.heading>{{ __('Nothing baked in this range') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No production run was logged on these days.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:heading size="lg" class="mt-8">{{ __('History') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Day') }}</flux:table.column>
                    <flux:table.column>{{ __('Runs') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                    <flux:table.column class="w-1/3">{{ __('Share of the biggest day') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->daily as $row)
                        <flux:table.row wire:key="day-{{ $row['day'] }}">
                            <flux:table.cell class="font-medium">{{ $row['day'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['runs']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                                    <div
                                        class="h-2 rounded-full bg-purple-500"
                                        style="width: {{ $this->biggestDay > 0 ? round($row['units'] / $this->biggestDay * 100, 1) : 0 }}%"
                                    ></div>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <flux:heading size="lg" class="mt-8">{{ __('Output by size') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Runs') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->byVariant as $row)
                        <flux:table.row wire:key="variant-{{ $loop->index }}">
                            <flux:table.cell class="font-medium">{{ $row['product'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['size'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['runs']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
