<?php

use App\Concerns\ReportRange;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\Restock;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Outlet performance')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-outlets');

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
     * Every location side by side, main branch first.
     *
     * Three aggregates keyed by outlet and joined here rather than one query
     * across sales, restocks and expenses at once: those tables meet only at
     * the outlet, and a single join across them would multiply the rows.
     *
     * @return Collection<int, array{outlet: Outlet, sales: int, units: int, takings: int, restocks: int, received: int, expenses: int, spent: int, net: int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $from = $this->range->from();
        $to = $this->range->to();

        $takings = Sale::takingsByOutlet($from, $to)->keyBy('outlet_id');
        $deliveries = Restock::deliveriesByOutlet($from, $to)->keyBy('outlet_id');
        $spending = Expense::totalsByOutlet($from, $to)->keyBy('outlet_id');

        return Outlet::query()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get()
            ->map(function (Outlet $outlet) use ($takings, $deliveries, $spending): array {
                $sold = $takings->get($outlet->id);
                $received = $deliveries->get($outlet->id);
                $spent = $spending->get($outlet->id);

                return [
                    'outlet' => $outlet,
                    'sales' => $sold['sales'] ?? 0,
                    'units' => $sold['units'] ?? 0,
                    'takings' => $sold['takings'] ?? 0,
                    'restocks' => $received['restocks'] ?? 0,
                    'received' => $received['units'] ?? 0,
                    'expenses' => $spent['entries'] ?? 0,
                    'spent' => $spent['total'] ?? 0,
                    'net' => ($sold['takings'] ?? 0) - ($spent['total'] ?? 0),
                ];
            });
    }

    /**
     * Takings everywhere, in centavos.
     */
    #[Computed]
    public function takings(): int
    {
        return (int) $this->rows->sum('takings');
    }

    /**
     * Spending everywhere, in centavos.
     */
    #[Computed]
    public function spent(): int
    {
        return (int) $this->rows->sum('spent');
    }

    /**
     * The best-performing location's takings, to scale the bars against.
     */
    #[Computed]
    public function busiest(): int
    {
        return (int) $this->rows->max('takings');
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Outlet performance') }}</flux:heading>
            <flux:text class="mt-2">{{ $this->range->label() }}</flux:text>
        </div>

        <flux:button :href="route('employee.reports.index')" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('All reports') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <x-report-range />

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Takings') }}</span>
            <p class="mt-1 text-3xl font-semibold">&#8369;{{ number_format($this->takings / 100, 2) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Every location together') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Spending') }}</span>
            <p class="mt-1 text-3xl font-semibold">&#8369;{{ number_format($this->spent / 100, 2) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Expenses filed against a location') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Takings less spending') }}</span>
            <p class="mt-1 text-3xl font-semibold {{ $this->takings - $this->spent < 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                &#8369;{{ number_format(($this->takings - $this->spent) / 100, 2) }}
            </p>
            {{-- Not profit: nothing here costs the ingredients or the labour in. --}}
            <p class="mt-1 text-sm text-zinc-500">{{ __('What was recorded, not a profit figure') }}</p>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Location by location') }}</flux:heading>

    <div class="mt-4 overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                <flux:table.column>{{ __('Sales') }}</flux:table.column>
                <flux:table.column>{{ __('Units sold') }}</flux:table.column>
                <flux:table.column>{{ __('Takings') }}</flux:table.column>
                <flux:table.column>{{ __('Restocks') }}</flux:table.column>
                <flux:table.column>{{ __('Units received') }}</flux:table.column>
                <flux:table.column>{{ __('Spending') }}</flux:table.column>
                <flux:table.column>{{ __('Takings less spending') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->rows as $row)
                    <flux:table.row wire:key="outlet-{{ $row['outlet']->id }}">
                        <flux:table.cell class="font-medium">
                            {{ $row['outlet']->name }}
                            @if ($row['outlet']->is_main_branch)
                                <flux:badge size="sm" color="purple" class="ms-2">{{ __('Main branch') }}</flux:badge>
                            @endif
                            @unless ($row['outlet']->is_active)
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Closed') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell>{{ number_format($row['sales']) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['units']) }}</flux:table.cell>
                        <flux:table.cell>
                            <div>&#8369;{{ number_format($row['takings'] / 100, 2) }}</div>
                            <div class="mt-1 h-1.5 w-24 rounded-full bg-zinc-100 dark:bg-zinc-700">
                                <div
                                    class="h-1.5 rounded-full bg-purple-500"
                                    style="width: {{ $this->busiest > 0 ? round($row['takings'] / $this->busiest * 100, 1) : 0 }}%"
                                ></div>
                            </div>
                        </flux:table.cell>
                        {{-- The main branch is the source under BR-004, so it receives nothing. --}}
                        <flux:table.cell class="text-zinc-500">{{ number_format($row['restocks']) }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ number_format($row['received']) }}</flux:table.cell>
                        <flux:table.cell>&#8369;{{ number_format($row['spent'] / 100, 2) }}</flux:table.cell>
                        <flux:table.cell class="{{ $row['net'] < 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                            &#8369;{{ number_format($row['net'] / 100, 2) }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
