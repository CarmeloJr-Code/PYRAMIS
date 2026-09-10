<?php

use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Products')] class extends Component {
    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-products');
    }

    /**
     * The catalogue, grouped for display.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->with(['category', 'variants'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Publish or withdraw a product from the catalogue.
     */
    public function toggleActive(int $productId): void
    {
        Gate::authorize('manage-products');

        $product = Product::findOrFail($productId);

        $product->update(['is_active' => ! $product->is_active]);

        unset($this->products);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Products') }}</flux:heading>
            <flux:text class="mt-2">{{ __('The Purple Yam Malaybalay catalogue. Each product carries one or more sellable sizes.') }}</flux:text>
        </div>

        <flux:button :href="route('employee.products.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New product') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->products->isEmpty())
        <flux:callout icon="squares-2x2">
            <flux:callout.heading>{{ __('No products yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add the first product to start building the catalogue.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Sizes') }}</flux:table.column>
                    <flux:table.column>{{ __('Price range') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->products as $product)
                        <flux:table.row :key="$product->id">
                            <flux:table.cell class="font-medium">{{ $product->name }}</flux:table.cell>
                            <flux:table.cell>{{ $product->category->name }}</flux:table.cell>
                            <flux:table.cell>{{ $product->variants->count() }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($product->variants->isEmpty())
                                    &mdash;
                                @else
                                    &#8369;{{ number_format((float) $product->variants->min('price'), 2) }}
                                    @if ($product->variants->count() > 1)
                                        &ndash; &#8369;{{ number_format((float) $product->variants->max('price'), 2) }}
                                    @endif
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$product->is_active ? 'green' : 'zinc'">
                                    {{ $product->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="toggleActive({{ $product->id }})"
                                    wire:key="toggle-{{ $product->id }}"
                                >
                                    {{ $product->is_active ? __('Deactivate') : __('Activate') }}
                                </flux:button>

                                <flux:button size="sm" variant="ghost" :href="route('employee.products.edit', $product)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
