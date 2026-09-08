<?php

use App\Actions\RecordProductionRun;
use App\Models\Ingredient;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Production runs')] class extends Component {
    #[Url(as: 'date', except: '')]
    public string $date = '';

    public ?int $product_variant_id = null;

    public int $quantity = 1;

    public string $notes = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-production');

        if ($this->date === '') {
            $this->date = now()->toDateString();
        }
    }

    /**
     * The sizes that can be produced — a size without a recipe cannot be run
     * against the stockroom, so it is not offered.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function producibleVariants(): Collection
    {
        return ProductVariant::query()
            ->whereHas('recipe.items')
            ->with(['product', 'recipe.items'])
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->name)
            ->values();
    }

    /**
     * The size currently chosen, if it is one that can be produced.
     */
    #[Computed]
    public function chosenVariant(): ?ProductVariant
    {
        return $this->producibleVariants->firstWhere('id', $this->product_variant_id);
    }

    /**
     * What the run about to be logged would take, checked against stock.
     *
     * The same figures the action will use, so what the baker is shown is what
     * actually happens.
     *
     * @return Collection<int, array{ingredient: Ingredient, required: string, short: bool}>
     */
    #[Computed]
    public function preview(): Collection
    {
        $variant = $this->chosenVariant;

        if ($variant === null || $this->quantity < 1) {
            return collect();
        }

        $requirements = $variant->recipe->requirementsInThousandths($this->quantity);

        $ingredients = Ingredient::query()
            ->withStock()
            ->whereIn('id', array_keys($requirements))
            ->get()
            ->keyBy('id');

        return collect($requirements)
            ->map(function (int $required, int $ingredientId) use ($ingredients): ?array {
                $ingredient = $ingredients->get($ingredientId);

                if ($ingredient === null) {
                    return null;
                }

                return [
                    'ingredient' => $ingredient,
                    'required' => Ingredient::formatQuantity($required),
                    'short' => $ingredient->stockInThousandths() < $required,
                ];
            })
            ->filter()
            ->sortBy(fn (array $row): string => $row['ingredient']->name)
            ->values();
    }

    /**
     * Whether the stockroom can cover the run as entered.
     */
    #[Computed]
    public function canCover(): bool
    {
        return $this->preview->isNotEmpty()
            && $this->preview->every(fn (array $row): bool => ! $row['short']);
    }

    /**
     * The runs logged on the chosen day, newest first.
     *
     * @return Collection<int, ProductionRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return ProductionRun::query()
            ->producedOn($this->date)
            ->with(['productVariant.product', 'recordedBy'])
            ->orderByDesc('produced_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The day's output per size.
     *
     * @return Collection<int, array{variant: ProductVariant, units: int}>
     */
    #[Computed]
    public function output(): Collection
    {
        return $this->runs
            ->groupBy('product_variant_id')
            ->map(fn (Collection $runs): array => [
                'variant' => $runs->first()->productVariant,
                'units' => $runs->sum('quantity'),
            ])
            ->sortBy(fn (array $row): string => $row['variant']->product->name.' '.$row['variant']->name)
            ->values();
    }

    /**
     * Log the run and take what it used out of the stockroom.
     */
    public function record(): void
    {
        Gate::authorize('access-production');

        $validated = $this->validate([
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // The form is client state, so the size is re-resolved from the
        // database and anything without a recipe is refused here, not just
        // left out of the picker.
        $variant = $this->producibleVariants->firstWhere('id', $validated['product_variant_id']);

        if ($variant === null) {
            $this->addError('product_variant_id', __('That size cannot be produced. Give it a recipe first.'));

            return;
        }

        try {
            $run = app(RecordProductionRun::class)->handle(
                $variant,
                Auth::user(),
                $validated['quantity'],
                $validated['notes'] ?: null,
            );
        } catch (\RuntimeException $exception) {
            $this->addError('quantity', $exception->getMessage());

            return;
        }

        $this->reset('notes');
        $this->quantity = 1;

        unset($this->runs, $this->output, $this->preview, $this->canCover, $this->producibleVariants);

        Flux::toast(variant: 'success', text: __('Run :reference logged.', ['reference' => $run->reference]));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Production runs') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Logging a bake takes its ingredients out of the stockroom and records what came out of the oven.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.production')" wire:navigate>
            {{ __('Back to production') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <flux:heading size="lg">{{ __('Log a run') }}</flux:heading>

            @if ($this->producibleVariants->isEmpty())
                <flux:callout icon="book-open" class="mt-4">
                    <flux:callout.heading>{{ __('Nothing can be produced yet') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('No size has a recipe with ingredients in it.') }}</flux:callout.text>
                    <flux:callout.link :href="route('employee.production.recipes.index')" wire:navigate>
                        {{ __('Write a recipe') }}
                    </flux:callout.link>
                </flux:callout>
            @else
                <form wire:submit="record" class="mt-4 flex flex-col gap-4">
                    <flux:select wire:model.live="product_variant_id" :label="__('What was made')" required>
                        <flux:select.option value="">{{ __('Choose a size') }}</flux:select.option>
                        @foreach ($this->producibleVariants as $variant)
                            <flux:select.option :value="$variant->id">
                                {{ $variant->product->name }} &middot; {{ $variant->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model.live="quantity"
                        :label="__('Units produced')"
                        type="number"
                        min="1"
                        required
                    />

                    <flux:input wire:model="notes" :label="__('Note (optional)')" />

                    <flux:button variant="primary" type="submit">{{ __('Log run') }}</flux:button>
                </form>

                @if ($this->preview->isNotEmpty())
                    <div class="mt-6">
                        <flux:heading size="sm">{{ __('This run will use') }}</flux:heading>

                        @unless ($this->canCover)
                            <flux:callout icon="exclamation-triangle" variant="danger" class="mt-2">
                                <flux:callout.text>{{ __('The stockroom cannot cover this run. Nothing is deducted unless all of it can be.') }}</flux:callout.text>
                            </flux:callout>
                        @endunless

                        <div class="mt-3 overflow-x-auto">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                                    <flux:table.column>{{ __('Needed') }}</flux:table.column>
                                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($this->preview as $row)
                                        <flux:table.row :key="$row['ingredient']->id">
                                            <flux:table.cell class="font-medium">{{ $row['ingredient']->name }}</flux:table.cell>
                                            <flux:table.cell>
                                                {{ $row['required'] }} {{ $row['ingredient']->unit->abbreviation() }}
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @if ($row['short'])
                                                    <flux:badge size="sm" color="red">
                                                        {{ $row['ingredient']->stock() }} {{ $row['ingredient']->unit->abbreviation() }}
                                                    </flux:badge>
                                                @else
                                                    {{ $row['ingredient']->stock() }} {{ $row['ingredient']->unit->abbreviation() }}
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                @endif
            @endif
        </div>

        <div class="lg:col-span-3">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <flux:heading size="lg">{{ __('Produced on this day') }}</flux:heading>

                <flux:input wire:model.live="date" type="date" :label="__('Day')" class="max-w-48" />
            </div>

            @if ($this->runs->isEmpty())
                <flux:callout icon="clock" class="mt-4">
                    <flux:callout.heading>{{ __('Nothing produced') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('No runs were logged on this day.') }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->output as $row)
                        <flux:badge color="zinc" wire:key="output-{{ $row['variant']->id }}">
                            {{ $row['variant']->product->name }} &middot; {{ $row['variant']->name }}: {{ $row['units'] }}
                        </flux:badge>
                    @endforeach
                </div>

                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Run') }}</flux:table.column>
                            <flux:table.column>{{ __('Time') }}</flux:table.column>
                            <flux:table.column>{{ __('Made') }}</flux:table.column>
                            <flux:table.column>{{ __('Units') }}</flux:table.column>
                            <flux:table.column>{{ __('Baker') }}</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->runs as $run)
                                <flux:table.row :key="$run->id">
                                    <flux:table.cell class="font-mono font-medium">{{ $run->reference }}</flux:table.cell>
                                    <flux:table.cell>{{ $run->produced_at->format('g:ia') }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $run->productVariant->product->name }} &middot; {{ $run->productVariant->name }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $run->quantity }}</flux:table.cell>
                                    <flux:table.cell>{{ $run->recordedBy->name }}</flux:table.cell>
                                    <flux:table.cell class="text-end">
                                        <flux:button size="sm" variant="ghost" :href="route('employee.production.runs.show', $run)" wire:navigate>
                                            {{ __('Open') }}
                                        </flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>
    </div>
</section>
