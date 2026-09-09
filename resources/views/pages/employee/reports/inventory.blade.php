<?php

use App\Concerns\FormatsQuantities;
use App\Concerns\ReportRange;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Inventory report')] class extends Component {
    use FormatsQuantities;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-inventory');

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
     * Stock as it stands, whatever the range says.
     *
     * Current stock is a standing figure, not a figure for a stretch of days:
     * the ledger's running total is what is in the stockroom now, and dating it
     * would report something nobody asked for.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function stocked(): Collection
    {
        return Ingredient::query()
            ->active()
            ->withStock()
            ->orderBy('name')
            ->get();
    }

    /**
     * The ingredients that need ordering.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function low(): Collection
    {
        return $this->stocked->filter(
            fn (Ingredient $ingredient): bool => $ingredient->isLowStock() || $ingredient->isOutOfStock(),
        );
    }

    /**
     * What moved through the stockroom over the range.
     *
     * @return Collection<int, array{ingredient_id: int, name: string, unit: \App\Enums\IngredientUnit, entries: int, received: int, used: int, adjusted: int}>
     */
    #[Computed]
    public function movement(): Collection
    {
        return InventoryMovement::summaryByIngredient($this->range->from(), $this->range->to());
    }

    /**
     * How many entries were made over the range, across every ingredient.
     */
    #[Computed]
    public function entries(): int
    {
        return (int) $this->movement->sum('entries');
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Inventory report') }}</flux:heading>
            <flux:text class="mt-2">{{ $this->range->label() }}</flux:text>
        </div>

        <flux:button :href="route('employee.reports.index')" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('All reports') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Ingredients tracked') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->stocked->count()) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Still in use') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Need ordering') }}</span>
            <p class="mt-1 text-3xl font-semibold {{ $this->low->isNotEmpty() ? 'text-amber-600 dark:text-amber-400' : '' }}">
                {{ number_format($this->low->count()) }}
            </p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('At or below their reorder level') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Ledger entries') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->entries) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Recorded over the range below') }}</p>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Current stock') }}</flux:heading>
    <flux:text class="mt-1">{{ __('As it stands today. The dates below apply to movement, not to this.') }}</flux:text>

    @if ($this->stocked->isEmpty())
        <flux:callout icon="beaker" class="mt-4">
            <flux:callout.heading>{{ __('Nothing tracked yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No ingredient has been added to the stockroom.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Reorder level') }}</flux:table.column>
                    <flux:table.column>{{ __('Standing') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->stocked as $ingredient)
                        <flux:table.row :key="$ingredient->id">
                            <flux:table.cell class="font-medium">{{ $ingredient->name }}</flux:table.cell>
                            <flux:table.cell>{{ $ingredient->stock() }} {{ $ingredient->unit->abbreviation() }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                @if ($ingredient->reorderLevelInThousandths() > 0)
                                    {{ $ingredient->reorderLevel() }} {{ $ingredient->unit->abbreviation() }}
                                @else
                                    {{ __('None set') }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($ingredient->isOutOfStock())
                                    <flux:badge size="sm" color="red">{{ __('Out of stock') }}</flux:badge>
                                @elseif ($ingredient->isLowStock())
                                    <flux:badge size="sm" color="amber">{{ __('Low') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Fine') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:heading size="lg" class="mt-8">{{ __('Movement') }}</flux:heading>

    <x-report-range class="mt-4" />

    @if ($this->movement->isEmpty())
        <flux:callout icon="arrows-right-left">
            <flux:callout.heading>{{ __('Nothing moved') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No receipt, usage or adjustment was recorded on these days.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                    <flux:table.column>{{ __('Received') }}</flux:table.column>
                    <flux:table.column>{{ __('Used') }}</flux:table.column>
                    <flux:table.column>{{ __('Adjusted') }}</flux:table.column>
                    <flux:table.column>{{ __('Net') }}</flux:table.column>
                    <flux:table.column>{{ __('Entries') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->movement as $row)
                        @php($net = $row['received'] + $row['used'] + $row['adjusted'])
                        <flux:table.row wire:key="movement-{{ $row['ingredient_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell class="text-emerald-600 dark:text-emerald-400">
                                {{ $this->formatQuantity($row['received']) }} {{ $row['unit']->abbreviation() }}
                            </flux:table.cell>
                            {{-- Usage is stored negative; shown unsigned under a column that already says which way it went. --}}
                            <flux:table.cell>
                                {{ $this->formatQuantity(abs($row['used'])) }} {{ $row['unit']->abbreviation() }}
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $row['adjusted'] >= 0 ? '+' : '−' }}{{ $this->formatQuantity(abs($row['adjusted'])) }}
                                {{ $row['unit']->abbreviation() }}
                            </flux:table.cell>
                            <flux:table.cell class="{{ $net < 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                {{ $net >= 0 ? '+' : '−' }}{{ $this->formatQuantity(abs($net)) }} {{ $row['unit']->abbreviation() }}
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ number_format($row['entries']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
