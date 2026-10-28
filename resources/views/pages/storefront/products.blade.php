<?php

use App\Actions\CountAvailableStock;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::storefront')] #[Title('Products')] class extends Component {
    #[Url(as: 'category', except: '')]
    public string $categorySlug = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

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
     * The words a customer typed, each of which a size has to match.
     *
     * LIKE wildcards are dropped rather than escaped: SQLite and Postgres
     * disagree on the default escape character, and nobody looks for a cake
     * by "%".
     *
     * @return list<string>
     */
    #[Computed]
    public function terms(): array
    {
        $words = preg_split('/\s+/', str_replace(['%', '_', '\\'], ' ', $this->search), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_slice($words ?: [], 0, 5));
    }

    /**
     * The published catalogue, one entry per size, optionally narrowed to one
     * category and to whatever the customer searched for. A product's sizes
     * sit together, cheapest first, which reads smallest to largest.
     *
     * Only active products are ever exposed — an unavailable size is still
     * shown, marked sold out, so customers can see the full menu (BR-012 keeps
     * everything else employee-side out of here).
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function variants(): Collection
    {
        return ProductVariant::query()
            ->whereHas('product', fn (Builder $product) => $product
                ->active()
                ->when(
                    $this->categorySlug !== '',
                    fn (Builder $query) => $query->whereHas('category', fn (Builder $c) => $c->where('slug', $this->categorySlug)),
                ))
            ->where(function (Builder $query): void {
                foreach ($this->terms as $term) {
                    $query->where(fn (Builder $match) => $match
                        ->whereLike('name', "%{$term}%")
                        ->orWhereHas('product', fn (Builder $product) => $product
                            ->whereLike('name', "%{$term}%")
                            ->orWhereHas('category', fn (Builder $category) => $category->whereLike('name', "%{$term}%"))));
                }
            })
            ->with('product.category')
            ->get()
            ->sortBy([
                fn (ProductVariant $a, ProductVariant $b): int => strcmp($a->product->name, $b->product->name),
                fn (ProductVariant $a, ProductVariant $b): int => (float) $a->price <=> (float) $b->price,
                fn (ProductVariant $a, ProductVariant $b): int => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * The outlets a customer can collect from — the same list checkout offers.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->active()->orderBy('name')->get();
    }

    /**
     * Units still available of each size at each pickup outlet.
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function stock(): array
    {
        return app(CountAvailableStock::class)->handle(
            $this->variants->where('is_available', true)->pluck('id'),
            $this->outlets->pluck('id'),
        );
    }
}; ?>

<div class="flex flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('The menu') }}</h1>
        <p class="max-w-2xl text-snow-500">{{ __('Every size has its own card, with its price and how many are ready at each pickup outlet. Pick one to order it.') }}</p>
    </header>

    <div class="flex flex-col gap-4">
        <div class="max-w-md">
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search the menu')"
                :aria-label="__('Search the menu')"
            />
        </div>

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
    </div>

    @if ($this->variants->isEmpty() && $this->terms !== [])
        <div class="flex flex-col items-center gap-4 rounded-xl border border-snow-200 bg-white p-8 text-center text-snow-500">
            <p>{{ __('Nothing on the menu matches “:search”.', ['search' => $search]) }}</p>

            <button
                type="button"
                wire:click="$set('search', '')"
                class="rounded-lg border border-snow-200 px-4 py-2 text-sm font-medium text-orchid-700 transition hover:border-orchid-300"
            >
                {{ __('Clear the search') }}
            </button>
        </div>
    @elseif ($this->variants->isEmpty())
        <p class="rounded-xl border border-snow-200 bg-white p-8 text-center text-snow-500">
            {{ __('Nothing on the menu here yet. Please check back soon.') }}
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->variants as $variant)
                <a
                    href="{{ route('products.show', ['product' => $variant->product, 'size' => $variant->id]) }}"
                    class="flex flex-col overflow-hidden rounded-xl border border-snow-200 bg-white transition hover:border-orchid-300"
                    wire:key="variant-{{ $variant->id }}"
                    wire:navigate
                >
                    <img src="{{ $variant->product->imageUrl() }}" alt="" width="400" height="300" loading="lazy" class="aspect-4/3 w-full border-b border-snow-200 object-cover">

                    <span class="flex flex-1 flex-col gap-3 p-6">
                        <span class="text-xs font-medium tracking-[0.15em] text-snow-400 uppercase">{{ $variant->product->category->name }}</span>

                        <span class="flex flex-col gap-1">
                            <span class="text-lg font-semibold text-orchid-800">{{ $variant->product->name }}</span>
                            <span class="text-sm font-medium text-snow-600">{{ $variant->name }}</span>
                        </span>

                        @if ($variant->product->description)
                            <span class="line-clamp-2 text-sm text-snow-500">{{ $variant->product->description }}</span>
                        @endif

                        <span class="mt-auto flex flex-col gap-3 pt-2">
                            @if ($variant->is_available)
                                <span class="text-lg font-semibold">&#8369;{{ number_format((float) $variant->price, 2) }}</span>

                                <span class="flex flex-col gap-1 border-t border-snow-200 pt-3 text-sm">
                                    @foreach ($this->outlets as $outlet)
                                        @php($units = $this->stock[$variant->id][$outlet->id] ?? 0)

                                        <span class="flex items-baseline justify-between gap-3" wire:key="stock-{{ $variant->id }}-{{ $outlet->id }}">
                                            <span class="text-snow-500">{{ $outlet->name }}</span>
                                            <span class="{{ $units > 0 ? 'font-medium text-orchid-700' : 'text-snow-400' }}">
                                                {{ $units > 0 ? __(':count available', ['count' => $units]) : __('0 available — pre-order') }}
                                            </span>
                                        </span>
                                    @endforeach
                                </span>
                            @else
                                <span class="flex items-baseline gap-2">
                                    <span class="text-lg font-semibold text-snow-400 line-through">&#8369;{{ number_format((float) $variant->price, 2) }}</span>
                                    <span class="rounded-full bg-snow-100 px-3 py-1 text-xs font-semibold text-snow-600">{{ __('Sold out') }}</span>
                                </span>
                            @endif
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
