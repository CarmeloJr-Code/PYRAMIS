<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::storefront')] #[Title('Your order')] class extends Component {
    /**
     * How many pre-orders one browser may place in an hour.
     *
     * A customer orders once per visit. Five is far past anything a real
     * one does, and well short of the volume that would make the cashier's
     * queue useless to work from.
     */
    private const HOURLY_ORDER_LIMIT = 5;

    /**
     * How many pre-orders one address may place in an hour.
     *
     * Four times the per-browser allowance, because an address here is not one
     * customer: mobile carriers put many behind a single one, so this counts a
     * neighbourhood and has to leave room for a busy afternoon. It is the floor
     * under the browser limit, not a replacement for it.
     */
    private const HOURLY_ADDRESS_LIMIT = 20;

    public ?int $outlet_id = null;

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_email = '';

    public string $pickup_at = '';

    public string $notes = '';

    /**
     * The cart, as variant id => quantity. Session-backed because customers
     * hold no account (BR-001).
     *
     * @return array<int, int>
     */
    protected function cart(): array
    {
        /** @var array<int, int> $cart */
        $cart = Session::get('cart', []);

        return $cart;
    }

    /**
     * The variants currently in the cart, resolved from the database.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function variants(): Collection
    {
        $ids = array_keys($this->cart());

        if ($ids === []) {
            return collect();
        }

        return ProductVariant::query()
            ->with('product')
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * The outlets a customer may choose.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()->active()->orderBy('name')->get();
    }

    /**
     * The cart total in pesos, from live catalogue prices.
     */
    #[Computed]
    public function total(): string
    {
        $cart = $this->cart();

        $centavos = $this->variants->sum(
            fn (ProductVariant $variant): int => (int) round((float) $variant->price * 100) * ($cart[$variant->id] ?? 0),
        );

        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Change the quantity of a line, or drop it at zero.
     */
    public function updateQuantity(int $variantId, int $quantity): void
    {
        $cart = $this->cart();

        if ($quantity < 1) {
            unset($cart[$variantId]);
        } else {
            $cart[$variantId] = min($quantity, 99);
        }

        Session::put('cart', $cart);

        unset($this->variants, $this->total);
    }

    /**
     * Remove a line entirely.
     */
    public function remove(int $variantId): void
    {
        $this->updateQuantity($variantId, 0);
    }

    /**
     * Place the pre-order.
     */
    public function submit(): void
    {
        // First, before the form is even read. A browser already over the
        // limit is over it whatever the form says, and saying so plainly
        // beats failing validation on an order that was never going to be
        // accepted anyway.
        $this->throttle();

        $validated = $this->validate([
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where('is_active', true),
            ],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'pickup_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $cart = $this->cart();

        if ($cart === []) {
            throw ValidationException::withMessages([
                'cart' => __('Your order is empty.'),
            ]);
        }

        // The cart came from the client, so nothing in it is trusted. Re-resolve
        // every variant and take the price from the database, never the request.
        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', array_keys($cart))
            ->get()
            ->filter(fn (ProductVariant $variant): bool => $variant->is_available && $variant->product->is_active);

        if ($variants->count() !== count($cart)) {
            throw ValidationException::withMessages([
                'cart' => __('Something in your order is no longer available. Please review it and try again.'),
            ]);
        }

        $order = DB::transaction(function () use ($validated, $variants, $cart): Order {
            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'] ?: null,
                'pickup_at' => $validated['pickup_at'],
                'notes' => $validated['notes'] ?: null,
                'status' => OrderStatus::Pending,
            ]);

            foreach ($variants as $variant) {
                $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $cart[$variant->id],
                    'unit_price' => $variant->price,
                ]);
            }

            return $order;
        });

        // Counted here, on an order actually written, and never on an attempt:
        // a customer fumbling the form should not spend what a real order
        // costs.
        foreach (array_keys($this->throttles()) as $key) {
            RateLimiter::hit($key, 3600);
        }

        Session::forget('cart');

        $this->redirectRoute('orders.show', ['order' => $order->reference], navigate: true);
    }

    /**
     * The limiters an order has to get past, as key => allowance.
     *
     * Customers hold no account (BR-001), so neither the session nor the
     * address is really an identity, and each covers the other's gap: clearing
     * cookies buys a fresh browser allowance, and a shared connection puts
     * strangers behind one address. So both are counted, with the address given
     * the looser limit because it is the one that can be a whole neighbourhood.
     *
     * @return array<string, int>
     */
    private function throttles(): array
    {
        return [
            'place-order:session:'.Session::getId() => self::HOURLY_ORDER_LIMIT,
            'place-order:address:'.request()->ip() => self::HOURLY_ADDRESS_LIMIT,
        ];
    }

    /**
     * Refuse an order that has already had its hour's worth.
     *
     * @throws ValidationException
     */
    private function throttle(): void
    {
        foreach ($this->throttles() as $key => $allowance) {
            if (! RateLimiter::tooManyAttempts($key, $allowance)) {
                continue;
            }

            // Which limit was reached is the shop's business, not the
            // customer's. All they can act on is when to come back.
            throw ValidationException::withMessages([
                'throttle' => __('That is more pre-orders than we can take from one place in an hour. Please try again in :minutes minutes.', [
                    'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                ]),
            ]);
        }
    }
}; ?>

<div class="flex flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('Your order') }}</h1>
        <p class="max-w-2xl text-snow-500">{{ __('Pre-order for pickup. No account needed — we will give you a reference to track it with.') }}</p>
    </header>

    @if ($errors->hasAny(['cart', 'throttle']))
        <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ $errors->first('cart') ?: $errors->first('throttle') }}</p>
    @endif

    @if ($this->variants->isEmpty())
        <div class="flex flex-col items-start gap-4 rounded-xl border border-snow-200 bg-white p-8">
            <p class="text-snow-500">{{ __('Your order is empty.') }}</p>
            <a href="{{ route('products.index') }}" class="rounded-lg bg-orchid-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orchid-700" wire:navigate>
                {{ __('Browse the menu') }}
            </a>
        </div>
    @else
        <div class="grid gap-8 lg:grid-cols-[1.1fr_1fr]">
            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">{{ __('Items') }}</h2>

                <ul class="flex flex-col gap-3">
                    @foreach ($this->variants as $variant)
                        <li class="flex items-center justify-between gap-4 rounded-xl border border-snow-200 bg-white p-4" wire:key="line-{{ $variant->id }}">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ $variant->product->name }}</span>
                                <span class="text-sm text-snow-500">{{ $variant->name }} &middot; &#8369;{{ number_format((float) $variant->price, 2) }}</span>
                            </div>

                            <div class="flex items-center gap-3">
                                <input
                                    type="number"
                                    min="1"
                                    max="99"
                                    value="{{ session('cart')[$variant->id] ?? 1 }}"
                                    wire:change="updateQuantity({{ $variant->id }}, $event.target.value)"
                                    class="w-20 rounded-lg border border-snow-200 px-3 py-1.5 text-sm"
                                    aria-label="{{ __('Quantity') }}"
                                >

                                <button type="button" wire:click="remove({{ $variant->id }})" class="text-sm text-snow-500 hover:text-red-600">
                                    {{ __('Remove') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="flex items-baseline justify-between border-t border-snow-200 pt-4 text-lg">
                    <span class="font-medium">{{ __('Total') }}</span>
                    <span class="font-semibold">&#8369;{{ $this->total }}</span>
                </p>
            </section>

            <form wire:submit="submit" class="flex flex-col gap-5 rounded-2xl border border-snow-200 bg-white p-6">
                <h2 class="text-lg font-semibold">{{ __('Your details') }}</h2>

                <flux:input wire:model="customer_name" :label="__('Name')" required />
                <flux:input wire:model="customer_phone" :label="__('Phone')" required />
                <flux:input wire:model="customer_email" :label="__('Email')" type="email" :description="__('Optional.')" />

                <flux:select wire:model="outlet_id" :label="__('Pickup outlet')" required>
                    <flux:select.option value="">{{ __('Choose where to collect') }}</flux:select.option>
                    @foreach ($this->outlets as $outlet)
                        <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="pickup_at" :label="__('Pickup date and time')" type="datetime-local" required />

                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" :description="__('Anything we should know — a message on the cake, for example.')" />

                <flux:button variant="primary" type="submit">{{ __('Place pre-order') }}</flux:button>
            </form>
        </div>
    @endif
</div>
