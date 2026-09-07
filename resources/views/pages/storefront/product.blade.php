<?php

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::storefront')] class extends Component {
    #[Locked]
    public Product $product;

    public ?int $selectedVariantId = null;

    /**
     * Resolve the product by slug, refusing anything withdrawn from the catalogue.
     */
    public function mount(Product $product): void
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'variants']);

        $this->product = $product;

        $this->selectedVariantId = $this->availableVariants->first()?->id;
    }

    /**
     * Name the browser tab after the product. Livewire only reads the static
     * #[Title] attribute, so a dynamic title goes through the view's title()
     * macro instead.
     */
    public function rendering(View $view): void
    {
        $view->title($this->product->name);
    }

    /**
     * The sizes the bakery can currently sell.
     *
     * @return Collection<int, \App\Models\ProductVariant>
     */
    #[Computed]
    public function availableVariants(): Collection
    {
        return $this->product->variants->where('is_available', true)->values();
    }
}; ?>

<div class="flex flex-col gap-8">
    <nav class="text-sm text-snow-500">
        <a href="{{ route('products.index') }}" class="hover:text-orchid-700" wire:navigate>{{ __('Menu') }}</a>
        <span class="px-2">/</span>
        <a href="{{ route('products.index', ['category' => $product->category->slug]) }}" class="hover:text-orchid-700" wire:navigate>
            {{ $product->category->name }}
        </a>
    </nav>

    <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr]">
        <div class="flex flex-col gap-4">
            <h1 class="text-3xl font-semibold tracking-tight text-orchid-800">{{ $product->name }}</h1>

            @if ($product->description)
                <p class="text-lg text-snow-600">{{ $product->description }}</p>
            @endif

            @if ($this->availableVariants->isEmpty())
                <p class="rounded-xl border border-snow-200 bg-snow-100 p-5 text-snow-600">
                    {{ __('This one is sold out right now. The sizes below are what we bake when it is back.') }}
                </p>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-2xl border border-snow-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('Sizes and prices') }}</h2>

            <ul class="flex flex-col gap-2">
                @foreach ($product->variants as $variant)
                    <li wire:key="variant-{{ $variant->id }}">
                        @if ($variant->is_available)
                            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-4 transition {{ $selectedVariantId === $variant->id ? 'border-orchid-600 bg-orchid-50' : 'border-snow-200 hover:border-orchid-300' }}">
                                <span class="flex items-center gap-3">
                                    <input
                                        type="radio"
                                        wire:model.live="selectedVariantId"
                                        value="{{ $variant->id }}"
                                        name="variant"
                                        class="size-4 accent-orchid-600"
                                    >
                                    <span class="font-medium">{{ $variant->name }}</span>
                                </span>

                                <span class="font-semibold">&#8369;{{ number_format((float) $variant->price, 2) }}</span>
                            </label>
                        @else
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-dashed border-snow-200 p-4 text-snow-400">
                                <span class="flex items-center gap-3">
                                    <span class="font-medium line-through">{{ $variant->name }}</span>
                                    <span class="rounded-full bg-snow-100 px-2 py-0.5 text-xs font-semibold text-snow-500">{{ __('Sold out') }}</span>
                                </span>

                                <span class="font-semibold line-through">&#8369;{{ number_format((float) $variant->price, 2) }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{-- Placing the order is the Phase 3 slice; this page only gets the
                 customer as far as choosing what they want. --}}
            <p class="rounded-lg bg-snow-100 p-4 text-sm text-snow-600">
                {{ __('Online pre-ordering is opening soon. In the meantime, please contact the shop to place an order.') }}
            </p>
        </div>
    </div>
</div>
