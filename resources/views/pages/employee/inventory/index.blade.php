<?php

use App\Models\Ingredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Inventory')] class extends Component {
    #[Url(as: 'inactive', except: false)]
    public bool $includeInactive = false;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-inventory');
    }

    /**
     * The stockroom, with each ingredient's quantity aggregated in SQL.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()
            ->withStock()
            ->unless($this->includeInactive, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    /**
     * How many ingredients have fallen to the level the business set.
     */
    #[Computed]
    public function lowStockCount(): int
    {
        return $this->ingredients
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->is_active && $ingredient->isLowStock())
            ->count();
    }
}; ?>

<section class="w-full">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Inventory') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Ingredient stock, counted from every movement recorded against it.') }}</flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:button :href="route('employee.inventory.usage')" variant="filled" icon="fire" wire:navigate>
                {{ __('Record usage') }}
            </flux:button>

            <flux:button :href="route('employee.inventory.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('New ingredient') }}
            </flux:button>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:switch wire:model.live="includeInactive" :label="__('Show ingredients no longer stocked')" />

        @if ($this->lowStockCount > 0)
            <flux:badge color="amber" icon="exclamation-triangle">
                {{ trans_choice('{1} :count ingredient is low|[2,*] :count ingredients are low', $this->lowStockCount, ['count' => $this->lowStockCount]) }}
            </flux:badge>
        @endif
    </div>

    @if ($this->ingredients->isEmpty())
        <flux:callout icon="beaker">
            <flux:callout.heading>{{ __('No ingredients yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add the first ingredient to start tracking stock.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Reorder at') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ingredients as $ingredient)
                        <flux:table.row :key="$ingredient->id">
                            <flux:table.cell class="font-medium">{{ $ingredient->name }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $ingredient->stock() }} {{ $ingredient->unit->abbreviation() }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($ingredient->reorderLevelInThousandths() > 0)
                                    {{ $ingredient->reorderLevel() }} {{ $ingredient->unit->abbreviation() }}
                                @else
                                    <span class="text-zinc-500">&mdash;</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (! $ingredient->is_active)
                                    <flux:badge size="sm" color="zinc">{{ __('Not stocked') }}</flux:badge>
                                @elseif ($ingredient->isOutOfStock())
                                    <flux:badge size="sm" color="red">{{ __('Out of stock') }}</flux:badge>
                                @elseif ($ingredient->isLowStock())
                                    <flux:badge size="sm" color="amber">{{ __('Low') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">{{ __('In stock') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('employee.inventory.show', $ingredient)" wire:navigate>
                                    {{ __('Open') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
