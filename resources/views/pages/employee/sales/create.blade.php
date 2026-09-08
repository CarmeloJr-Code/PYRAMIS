<?php

use App\Actions\RecordProductStockMovement;
use App\Enums\ProductStockMovementType;
use App\Enums\SaleStatus;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Sale;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record counter sale')] class extends Component {
    public ?int $outlet_id = null;

    /**
     * The lines being rung up.
     *
     * @var array<int, array{product_variant_id: int|null, quantity: int}>
     */
    public array $lines = [];

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-sales');

        $this->outlet_id = Outlet::query()->active()->orderBy('name')->value('id');

        $this->addLine();
    }

    /**
     * The outlets a sale can be rung up at.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->active()->orderBy('name')->get();
    }

    /**
     * Everything the bakery can sell right now, grouped for the picker.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function sellableVariants(): Collection
    {
        return ProductVariant::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('product')
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->name)
            ->values();
    }

    /**
     * The running total, from live catalogue prices.
     */
    #[Computed]
    public function total(): string
    {
        $centavos = 0;

        foreach ($this->lines as $line) {
            $variant = $this->sellableVariants->firstWhere('id', $line['product_variant_id']);

            if ($variant !== null) {
                $centavos += (int) round((float) $variant->price * 100) * max(0, (int) $line['quantity']);
            }
        }

        return number_format($centavos / 100, 2);
    }

    /**
     * Append an empty line.
     */
    public function addLine(): void
    {
        $this->lines[] = ['product_variant_id' => null, 'quantity' => 1];
    }

    /**
     * Drop a line. A sale must keep at least one.
     */
    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);

        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }

        unset($this->total);
    }

    /**
     * Record the sale.
     */
    public function save(): void
    {
        Gate::authorize('access-sales');

        $validated = $this->validate([
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where('is_active', true),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        // Re-resolve every variant from the database. The form is client state,
        // so availability and price are both taken from the row, never the
        // request — the same rule the storefront checkout follows.
        $variantIds = array_column($validated['lines'], 'product_variant_id');

        $variants = ProductVariant::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($variantIds)) {
            $this->addError('lines', __('Something on this sale is no longer available. Please check the items.'));

            return;
        }

        $outlet = Outlet::query()->active()->findOrFail($validated['outlet_id']);

        $sale = DB::transaction(function () use ($validated, $variants, $outlet): Sale {
            $sale = Sale::create([
                'outlet_id' => $outlet->id,
                // A walk-in sale has no originating pre-order.
                'order_id' => null,
                'recorded_by' => Auth::id(),
                'status' => SaleStatus::Completed,
                'sold_at' => now(),
            ]);

            $movements = app(RecordProductStockMovement::class);

            foreach ($validated['lines'] as $line) {
                $variant = $variants[$line['product_variant_id']];

                $sale->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $variant->price,
                ]);

                // The goods leave the outlet they were rung up at. A sale may
                // take that shelf negative — it went over the counter whatever
                // the count believed.
                $movements->handle(
                    $variant,
                    $outlet,
                    Auth::user(),
                    ProductStockMovementType::Sale,
                    -$line['quantity'],
                    null,
                    null,
                    null,
                    $sale,
                );
            }

            return $sale;
        });

        Flux::toast(variant: 'success', text: __('Sale :reference recorded.', ['reference' => $sale->reference]));

        $this->redirectRoute('employee.sales.index', ['date' => $sale->sold_at->toDateString()], navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Record counter sale') }}</flux:heading>
    <flux:text class="mt-2">{{ __('For anything sold over the counter. Pre-orders are billed automatically when you complete them.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->sellableVariants->isEmpty())
        <flux:callout icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Nothing is available to sell') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Every product is either inactive or has no available sizes.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:select wire:model="outlet_id" :label="__('Outlet')" required>
                <flux:select.option value="">{{ __('Choose an outlet') }}</flux:select.option>
                @foreach ($this->outlets as $outlet)
                    <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:separator variant="subtle" />

            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Items') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addLine">
                        {{ __('Add item') }}
                    </flux:button>
                </div>

                @error('lines')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                @foreach ($lines as $index => $line)
                    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="line-{{ $index }}">
                        <flux:select wire:model.live="lines.{{ $index }}.product_variant_id" :label="__('Item')" class="flex-1">
                            <flux:select.option value="">{{ __('Choose an item') }}</flux:select.option>
                            @foreach ($this->sellableVariants as $variant)
                                <flux:select.option :value="$variant->id">
                                    {{ $variant->product->name }} &middot; {{ $variant->name }} &mdash; &#8369;{{ number_format((float) $variant->price, 2) }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            wire:model.live="lines.{{ $index }}.quantity"
                            :label="__('Qty')"
                            type="number"
                            min="1"
                            max="999"
                            class="md:w-28"
                        />

                        <div class="md:pt-7">
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="trash"
                                type="button"
                                wire:click="removeLine({{ $index }})"
                                :disabled="count($lines) === 1"
                            />
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="flex items-baseline justify-between border-t border-zinc-200 pt-4 text-lg dark:border-zinc-700">
                <span class="font-medium">{{ __('Total') }}</span>
                <span class="font-semibold">&#8369;{{ $this->total }}</span>
            </p>

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Record sale') }}</flux:button>
                <flux:button variant="ghost" :href="route('employee.sales.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
