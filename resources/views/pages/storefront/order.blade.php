<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Rules\PhilippineMobile;
use Illuminate\Support\Carbon;
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

    public string $pickup_date = '';

    public string $pickup_time = '';

    public string $notes = '';

    /**
     * The fields a stray space can spoil — trimmed before they are checked,
     * so a name of nothing but spaces reads as no name at all.
     */
    private const TRIMMED = ['customer_name', 'customer_phone', 'customer_email'];

    /**
     * The checkout rules, shared by the check as each field is left and the
     * one on submit.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where('is_active', true),
            ],
            'customer_name' => ['required', 'string', 'min:2', 'max:255', "regex:/^[\\pL\\s.'\\-]+$/u"],
            'customer_phone' => ['required', 'string', 'max:20', new PhilippineMobile],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'customer_name.regex' => __('Please use letters only in your name.'),
        ];
    }

    /**
     * Check a field as soon as the customer leaves it, not only on submit.
     */
    public function updated(string $property): void
    {
        if (in_array($property, self::TRIMMED, true)) {
            $this->{$property} = trim($this->{$property});
        }

        if (array_key_exists($property, $this->rules())) {
            $this->validateOnly($property);
        }
    }

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
     *
     * The box hands over whatever was typed in it, so anything that is not
     * a whole number leaves the line as it was rather than guessing at one.
     */
    public function updateQuantity(int $variantId, int|string $quantity): void
    {
        if (is_string($quantity)) {
            if (! ctype_digit($quantity)) {
                unset($this->variants, $this->total);

                return;
            }

            $quantity = (int) $quantity;
        }

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

        foreach (self::TRIMMED as $property) {
            $this->{$property} = trim($this->{$property});
        }

        $validated = $this->validate();

        // Each half can be fine on its own and the pair still be in the past:
        // a time earlier than now on today's date. The time is the half the
        // customer can fix without changing their day, so it takes the error.
        $pickupAt = Carbon::createFromFormat('Y-m-d H:i', $validated['pickup_date'].' '.$validated['pickup_time']);

        if ($pickupAt->isPast()) {
            throw ValidationException::withMessages([
                'pickup_time' => __('That time has already passed. Please choose a later one.'),
            ]);
        }

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

        $order = DB::transaction(function () use ($validated, $pickupAt, $variants, $cart): Order {
            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => PhilippineMobile::normalize($validated['customer_phone']),
                'customer_email' => $validated['customer_email'] ?: null,
                'pickup_at' => $pickupAt,
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

                <a href="{{ route('products.index') }}" class="self-start rounded-lg border border-orchid-600 px-4 py-2 text-sm font-semibold text-orchid-700 hover:bg-orchid-50" wire:navigate>
                    {{ __('Add more products') }}
                </a>
            </section>

            <form wire:submit="submit" class="flex flex-col gap-5 rounded-2xl border border-snow-200 bg-white p-6">
                <h2 class="text-lg font-semibold">{{ __('Your details') }}</h2>

                <flux:input wire:model.blur="customer_name" :label="__('Name')" maxlength="255" autocomplete="name" required />
                <flux:input
                    wire:model.blur="customer_phone"
                    :label="__('Phone')"
                    type="tel"
                    inputmode="tel"
                    maxlength="20"
                    autocomplete="tel"
                    placeholder="0917 123 4567"
                    :description="__('Philippine mobile number.')"
                    required
                />
                <flux:input wire:model.blur="customer_email" :label="__('Email')" type="email" maxlength="255" autocomplete="email" :description="__('Optional.')" />

                <flux:select wire:model="outlet_id" :label="__('Pickup outlet')" required>
                    <flux:select.option value="">{{ __('Choose where to collect') }}</flux:select.option>
                    @foreach ($this->outlets as $outlet)
                        <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model.blur="pickup_date" :label="__('Pickup date')" type="date" :min="today()->toDateString()" required />
                    <flux:input wire:model.blur="pickup_time" :label="__('Pickup time')" type="time" required />
                </div>

                <flux:textarea wire:model.blur="notes" :label="__('Notes')" rows="2" maxlength="1000" :description="__('Anything we should know — a message on the cake, for example.')" />

                <flux:button variant="primary" type="submit">{{ __('Place pre-order') }}</flux:button>
            </form>
        </div>
    @endif
</div>
