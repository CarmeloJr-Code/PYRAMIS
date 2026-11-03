<?php

use App\Actions\CountAvailableStock;
use App\Enums\PickupWhen;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::storefront')] class extends Component {
    #[Locked]
    public Product $product;

    #[Locked]
    public ?int $variantId = null;

    /**
     * Resolve the product by slug, refusing anything withdrawn from the catalogue.
     *
     * Each size has its own card on the menu, so this page is about the one a
     * card linked to with ?size= — sold out or not, so the customer sees what
     * they clicked. Without a size of this product, it shows the first that
     * can be sold, and failing that the first there is.
     */
    public function mount(Product $product): void
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'variants']);

        $this->product = $product;

        $this->variantId = $product->variants->firstWhere('id', request()->integer('size'))?->id
            ?? $product->variants->firstWhere('is_available', true)?->id
            ?? $product->variants->first()?->id;
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
     * The size this page is about, if the product has any.
     */
    #[Computed]
    public function variant(): ?ProductVariant
    {
        return $this->product->variants->firstWhere('id', $this->variantId);
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
     * Units of this size still to be had today at each pickup outlet.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function stock(): array
    {
        if (! $this->variant?->is_available) {
            return [];
        }

        return app(CountAvailableStock::class)->handle(
            collect([$this->variant->id]),
            $this->outlets->pluck('id'),
        )[$this->variant->id] ?? [];
    }

    /**
     * Why "Buy now" cannot be pressed, or null when it can.
     *
     * Buy now means collecting today, so it needs a unit left at some outlet
     * and a pickup hour that has not gone by. A pre-order needs neither.
     */
    #[Computed]
    public function cannotBuyToday(): ?string
    {
        if (array_sum($this->stock) < 1) {
            return __('Out of stock today');
        }

        if (! PickupWhen::todayHasPickupsLeft()) {
            return __('No more pickups today');
        }

        return null;
    }

    /**
     * Put this size in the session cart, collected today or on a later day.
     *
     * The cart is only a convenience — checkout re-resolves every variant and
     * recounts today's stock from the database, so nothing here is trusted at
     * submit time. One order has one pickup, so the button pressed last sets
     * it for the whole cart.
     */
    public function addToOrder(string $when): void
    {
        $variant = $this->variant;
        $pickup = PickupWhen::tryFrom($when);

        if ($variant === null || ! $variant->is_available || $pickup === null) {
            return;
        }

        if ($pickup === PickupWhen::Today && $this->cannotBuyToday !== null) {
            return;
        }

        /** @var array<int, int> $cart */
        $cart = Session::get('cart', []);

        $cart[$variant->id] = min(($cart[$variant->id] ?? 0) + 1, 99);

        Session::put('cart', $cart);
        Session::put('cart_pickup', $pickup->value);

        $this->redirectRoute('order', navigate: true);
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
            <img src="{{ $product->imageUrl() }}" alt="" width="400" height="300" class="aspect-4/3 w-full rounded-2xl border border-snow-200 object-cover">

            <h1 class="text-3xl font-semibold tracking-tight text-orchid-800">{{ $product->name }}</h1>

            @if ($product->description)
                <p class="text-lg text-snow-600">{{ $product->description }}</p>
            @endif
        </div>

        <div class="flex flex-col gap-5 self-start rounded-2xl border border-snow-200 bg-white p-6">
            @if ($this->variant === null)
                <p class="rounded-lg bg-snow-100 p-4 text-sm text-snow-600">
                    {{ __('There is nothing to order here yet. Please check back soon.') }}
                </p>
            @else
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-semibold">{{ $this->variant->name }}</h2>
                    <span class="text-xl font-semibold {{ $this->variant->is_available ? '' : 'text-snow-400 line-through' }}">&#8369;{{ number_format((float) $this->variant->price, 2) }}</span>
                </div>

                @if ($this->variant->is_available)
                    <x-stock-by-outlet :outlets="$this->outlets" :units="$this->stock" class="border-t border-snow-200 pt-4" />

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            wire:click="addToOrder('today')"
                            @disabled($this->cannotBuyToday !== null)
                            data-test="buy-now"
                            class="flex flex-col items-center rounded-lg bg-orchid-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-orchid-700 disabled:cursor-not-allowed disabled:bg-snow-200 disabled:text-snow-500"
                        >
                            {{ $this->cannotBuyToday ?? __('Buy now') }}
                            <span class="text-xs font-normal opacity-80">{{ __('Pick up today') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="addToOrder('later')"
                            data-test="pre-order"
                            class="flex flex-col items-center rounded-lg border border-orchid-600 px-5 py-3 text-sm font-semibold text-orchid-700 transition hover:bg-orchid-50"
                        >
                            {{ __('Pre-order') }}
                            <span class="text-xs font-normal opacity-80">{{ __('Pick up tomorrow or later') }}</span>
                        </button>
                    </div>
                @else
                    <p class="flex items-center gap-3 rounded-lg bg-snow-100 p-4 text-sm text-snow-600">
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-snow-500">{{ __('Sold out') }}</span>
                        {{ __('This size is sold out right now. Please check back soon.') }}
                    </p>
                @endif
            @endif

            @if ($product->variants->count() > 1)
                <a
                    href="{{ route('products.index', ['q' => $product->name]) }}"
                    class="text-center text-sm font-medium text-orchid-700 hover:text-orchid-800"
                    wire:navigate
                >
                    {{ __('See the other sizes') }}
                </a>
            @endif
        </div>
    </div>
</div>
