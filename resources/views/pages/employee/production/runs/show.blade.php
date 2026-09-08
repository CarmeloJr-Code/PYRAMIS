<?php

use App\Models\ProductionRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ProductionRun $productionRun;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(ProductionRun $productionRun): void
    {
        Gate::authorize('access-production');

        $this->productionRun = $productionRun->load([
            'productVariant.product',
            'recordedBy',
            'movements.ingredient',
        ]);
    }

    /**
     * Name the tab after the run being read.
     */
    public function rendering(View $view): void
    {
        $view->title(__('Run :reference', ['reference' => $this->productionRun->reference]));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="font-mono">{{ $productionRun->reference }}</flux:heading>
            <flux:text class="mt-2">
                {{ __(':quantity × :product · :variant, baked by :baker on :date.', [
                    'quantity' => $productionRun->quantity,
                    'product' => $productionRun->productVariant->product->name,
                    'variant' => $productionRun->productVariant->name,
                    'baker' => $productionRun->recordedBy->name,
                    'date' => $productionRun->produced_at->format('d M Y, g:ia'),
                ]) }}
            </flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.production.runs.index', ['date' => $productionRun->produced_at->toDateString()])" wire:navigate>
            {{ __('Back to runs') }}
        </flux:button>
    </div>

    @if ($productionRun->notes)
        <flux:callout icon="pencil" class="mt-6">
            <flux:callout.text>{{ $productionRun->notes }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <flux:heading size="lg">{{ __('What it used') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Taken from the stock ledger, not recalculated — a later change to the recipe cannot rewrite what this bake actually consumed.') }}</flux:text>

    <div class="mt-4 overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                <flux:table.column>{{ __('Used') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($productionRun->movements->sortBy(fn ($movement) => $movement->ingredient->name) as $movement)
                    <flux:table.row :key="$movement->id">
                        <flux:table.cell class="font-medium">{{ $movement->ingredient->name }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $movement->magnitude() }} {{ $movement->ingredient->unit->abbreviation() }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
