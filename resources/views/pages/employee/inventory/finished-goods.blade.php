<?php

use App\Actions\RecordProductStockMovement;
use App\Enums\ProductStockMovementType;
use App\Models\Outlet;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Finished goods')] class extends Component {
    #[Url(as: 'outlet', except: 0)]
    public int $outletId = 0;

    public ?int $product_variant_id = null;

    public string $quantity = '';

    public string $direction = 'in';

    public string $note = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-inventory');

        if ($this->outletId === 0 || $this->outlets->doesntContain('id', $this->outletId)) {
            // The main branch is where production lands, so it is the shelf
            // most often being looked at.
            $this->outletId = (int) ($this->outlets->firstWhere('is_main_branch', true)?->id
                ?? $this->outlets->first()?->id
                ?? 0);
        }
    }

    /**
     * The places finished goods can sit.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * The shelf currently being looked at.
     */
    #[Computed]
    public function outlet(): ?Outlet
    {
        return $this->outlets->firstWhere('id', $this->outletId);
    }

    /**
     * Everything the bakery makes, with what is on this shelf.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function variants(): Collection
    {
        $outlet = $this->outlet;

        if ($outlet === null) {
            return collect();
        }

        return ProductVariant::query()
            ->withStockAt($outlet)
            ->with('product')
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->name)
            ->values();
    }

    /**
     * The sizes actually on the shelf.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function inStock(): Collection
    {
        return $this->variants->filter(fn (ProductVariant $variant): bool => $variant->stockInUnits() !== 0)->values();
    }

    /**
     * How many finished units are sitting here in total.
     */
    #[Computed]
    public function onHand(): int
    {
        return $this->variants->sum(fn (ProductVariant $variant): int => $variant->stockInUnits());
    }

    /**
     * The shelf's recent history.
     *
     * @return Collection<int, ProductStockMovement>
     */
    #[Computed]
    public function movements(): Collection
    {
        $outlet = $this->outlet;

        if ($outlet === null) {
            return collect();
        }

        return ProductStockMovement::query()
            ->at($outlet)
            ->with(['productVariant.product', 'recordedBy', 'productionRun'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Correct the count on this shelf.
     *
     * Only adjustments are recorded by hand. Production credits the main branch
     * on its own, and moving stock to an outlet is a restock, not a pair of
     * corrections.
     */
    public function adjust(): void
    {
        Gate::authorize('access-inventory');

        $validated = $this->validate([
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $outlet = $this->outlet;
        $variant = $this->variants->firstWhere('id', $validated['product_variant_id']);

        if ($outlet === null || $variant === null) {
            $this->addError('product_variant_id', __('That size is no longer in the catalogue.'));

            return;
        }

        $quantity = $validated['direction'] === 'out'
            ? -$validated['quantity']
            : $validated['quantity'];

        try {
            app(RecordProductStockMovement::class)->handle(
                $variant,
                $outlet,
                Auth::user(),
                ProductStockMovementType::Adjustment,
                $quantity,
                $validated['note'],
            );
        } catch (\RuntimeException $exception) {
            $this->addError('quantity', $exception->getMessage());

            return;
        }

        $this->reset('quantity', 'note');

        unset($this->variants, $this->inStock, $this->onHand, $this->movements);

        Flux::toast(variant: 'success', text: __('Stock corrected.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Finished goods') }}</flux:heading>
            <flux:text class="mt-2">{{ __('What is on the shelf, counted from every movement recorded against it.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.inventory.index')" wire:navigate>
            {{ __('Ingredients') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->outlet === null)
        <flux:callout icon="building-storefront">
            <flux:callout.heading>{{ __('No locations yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Finished goods sit at a location, and none exists.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <flux:select wire:model.live="outletId" :label="__('Location')" class="max-w-xs">
                @foreach ($this->outlets as $outlet)
                    <flux:select.option :value="$outlet->id">
                        {{ $outlet->name }}{{ $outlet->is_main_branch ? ' · '.__('Main branch') : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex flex-col items-end">
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('On hand') }}</span>
                <span class="text-3xl font-semibold">{{ $this->onHand }}</span>
                <span class="text-sm text-zinc-500">
                    {{ trans_choice('{1} :count size|[2,*] :count sizes', $this->inStock->count(), ['count' => $this->inStock->count()]) }}
                </span>
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-5">
            <div class="lg:col-span-3">
                @if ($this->inStock->isEmpty())
                    <flux:callout icon="cake">
                        <flux:callout.heading>{{ __('The shelf is empty') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ $this->outlet->is_main_branch
                                ? __('Log a production run to put finished goods here.')
                                : __('This outlet has received nothing yet.') }}
                        </flux:callout.text>
                    </flux:callout>
                @else
                    <div class="overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Product') }}</flux:table.column>
                                <flux:table.column>{{ __('Size') }}</flux:table.column>
                                <flux:table.column>{{ __('On hand') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->inStock as $variant)
                                    <flux:table.row :key="$variant->id">
                                        <flux:table.cell class="font-medium">{{ $variant->product->name }}</flux:table.cell>
                                        <flux:table.cell>{{ $variant->name }}</flux:table.cell>
                                        <flux:table.cell>{{ $variant->stockInUnits() }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <flux:heading size="lg" class="mt-8">{{ __('Recent movements') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Kept as recorded. A miscount is corrected by an adjustment, never by editing history.') }}</flux:text>

                @if ($this->movements->isEmpty())
                    <flux:callout icon="clock" class="mt-4">
                        <flux:callout.heading>{{ __('Nothing recorded') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('No finished goods have moved here yet.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('When') }}</flux:table.column>
                                <flux:table.column>{{ __('Size') }}</flux:table.column>
                                <flux:table.column>{{ __('Change') }}</flux:table.column>
                                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                                <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->movements as $movement)
                                    <flux:table.row :key="$movement->id">
                                        <flux:table.cell>{{ $movement->occurred_at->format('d M Y, g:ia') }}</flux:table.cell>
                                        <flux:table.cell>
                                            {{ $movement->productVariant->product->name }} &middot; {{ $movement->productVariant->name }}
                                        </flux:table.cell>
                                        <flux:table.cell class="{{ $movement->isIncrease() ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $movement->signedQuantity() }}
                                        </flux:table.cell>
                                        <flux:table.cell class="text-zinc-500">
                                            @if ($movement->productionRun)
                                                <a href="{{ route('employee.production.runs.show', $movement->productionRun) }}" class="font-mono underline" wire:navigate>
                                                    {{ $movement->productionRun->reference }}
                                                </a>
                                            @else
                                                {{ $movement->note ?? $movement->type->label() }}
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $movement->recordedBy->name }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif
            </div>

            <div class="lg:col-span-2">
                <flux:card>
                    <flux:heading size="lg">{{ __('Correct the count') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('For a miscount or a tray lost. Production and restocking record themselves.') }}</flux:text>

                    <form wire:submit="adjust" class="mt-4 flex flex-col gap-4">
                        <flux:select wire:model="product_variant_id" :label="__('Size')" required>
                            <flux:select.option value="">{{ __('Choose a size') }}</flux:select.option>
                            @foreach ($this->variants as $variant)
                                <flux:select.option :value="$variant->id">
                                    {{ $variant->product->name }} &middot; {{ $variant->name }} ({{ $variant->stockInUnits() }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:radio.group wire:model="direction" :label="__('Direction')" variant="segmented">
                            <flux:radio value="in" :label="__('Add')" />
                            <flux:radio value="out" :label="__('Remove')" />
                        </flux:radio.group>

                        <flux:input wire:model="quantity" :label="__('Units')" type="number" min="1" required />

                        <flux:input wire:model="note" :label="__('Reason')" required />

                        <flux:button variant="primary" type="submit">{{ __('Record correction') }}</flux:button>
                    </form>
                </flux:card>
            </div>
        </div>
    @endif
</section>
