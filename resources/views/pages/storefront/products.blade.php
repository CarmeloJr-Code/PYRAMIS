<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::storefront')] #[Title('Products')] class extends Component {
    #[Url(as: 'category', except: '')]
    public string $categorySlug = '';

    /**
     * The categories a customer can filter by.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * The published catalogue, optionally narrowed to one category.
     *
     * Only active products are ever exposed — an unavailable product is still
     * shown, marked sold out, so customers can see the full menu (BR-012 keeps
     * everything else employee-side out of here).
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->active()
            ->with(['category', 'variants'])
            ->when(
                $this->categorySlug !== '',
                fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $this->categorySlug)),
            )
            ->orderBy('name')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('The menu') }}</h1>
        <p class="max-w-2xl text-snow-500">{{ __('Every cake is priced by size. Pick a product to see the sizes we bake and what each one costs.') }}</p>
    </header>

    <nav class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="$set('categorySlug', '')"
            class="rounded-full border px-4 py-1.5 text-sm font-medium transition {{ $categorySlug === '' ? 'border-orchid-600 bg-orchid-600 text-white' : 'border-snow-200 bg-white text-snow-600 hover:border-orchid-300' }}"
        >
            {{ __('All') }}
        </button>

        @foreach ($this->categories as $category)
            <button
                type="button"
                wire:key="filter-{{ $category->id }}"
                wire:click="$set('categorySlug', '{{ $category->slug }}')"
                class="rounded-full border px-4 py-1.5 text-sm font-medium transition {{ $categorySlug === $category->slug ? 'border-orchid-600 bg-orchid-600 text-white' : 'border-snow-200 bg-white text-snow-600 hover:border-orchid-300' }}"
            >
                {{ $category->name }}
            </button>
        @endforeach
    </nav>

    @if ($this->products->isEmpty())
        <p class="rounded-xl border border-snow-200 bg-white p-8 text-center text-snow-500">
            {{ __('Nothing on the menu here yet. Please check back soon.') }}
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->products as $product)
                @php($available = $product->variants->where('is_available', true))

                <a
                    href="{{ route('products.show', $product) }}"
                    class="flex flex-col gap-3 rounded-xl border border-snow-200 bg-white p-6 transition hover:border-orchid-300"
                    wire:key="product-{{ $product->id }}"
                    wire:navigate
                >
                    <span class="text-xs font-medium tracking-[0.15em] text-snow-400 uppercase">{{ $product->category->name }}</span>

                    <span class="text-lg font-semibold text-orchid-800">{{ $product->name }}</span>

                    @if ($product->description)
                        <span class="line-clamp-2 text-sm text-snow-500">{{ $product->description }}</span>
                    @endif

                    <span class="mt-auto flex items-baseline gap-2 pt-2">
                        @if ($available->isEmpty())
                            <span class="rounded-full bg-snow-100 px-3 py-1 text-xs font-semibold text-snow-600">{{ __('Sold out') }}</span>
                        @else
                            <span class="text-sm text-snow-500">{{ __('from') }}</span>
                            <span class="text-lg font-semibold">&#8369;{{ number_format((float) $available->min('price'), 2) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
