<?php

use App\Actions\CountAvailableStock;
use App\Actions\NotifyEmployees;
use App\Concerns\EmailValidationRules;
use App\Enums\OrderStatus;
use App\Enums\PickupWhen;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Notifications\OrderPlaced;
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
    use EmailValidationRules;

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

    /**
     * Today ("Buy now") or a later day (pre-order), as a PickupWhen value.
     */
    public string $pickup_when = 'later';

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_email = '';

    public string $pickup_date = '';

    public string $pickup_time = '';

    public string $notes = '';

    /**
     * The fields a stray space can spoil — trimmed before they are checked,
     * so a name of nothing but spaces reads as no name at all. The email is
     * lowercased as well, by tidy().
     */
    private const TRIMMED = ['customer_name', 'customer_phone', 'customer_email'];

    /**
     * Pick up where the product page left off: the button pressed last chose
     * today or a later day for the whole cart.
     */
    public function mount(): void
    {
        $this->pickup_when = PickupWhen::tryFrom((string) Session::get('cart_pickup'))?->value ?? PickupWhen::Later->value;

        if ($this->forToday()) {
            $this->pickup_date = today()->toDateString();
        }
    }

    /**
     * Whether this order is to be collected today.
     */
    public function forToday(): bool
    {
        return $this->pickup_when === PickupWhen::Today->value;
    }

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
            'customer_phone' => ['required', 'string', new PhilippineMobile],
            'customer_email' => $this->optionalEmailRules(),
            'pickup_when' => ['required', Rule::enum(PickupWhen::class)],
            // Buy now is collected today; a pre-order is for tomorrow or after.
            'pickup_date' => ['required', 'date_format:Y-m-d', $this->forToday() ? 'date_equals:today' : 'after:today'],
            // Any hour of the window. One already gone today is refused on
            // submit, with a message that says so.
            'pickup_time' => ['required', Rule::in($this->pickupHours())],
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
            'pickup_time.in' => __('Please choose a pickup time between 8:00 AM and 7:00 PM.'),
            'pickup_date.after' => __('A pre-order is for tomorrow or later. To collect today, choose "Pick up today".'),
            'pickup_date.date_equals' => __('"Pick up today" is collected today.'),
        ];
    }

    /**
     * Every hour a pickup may be booked for, as H:i, opening to closing.
     *
     * @return list<string>
     */
    private function pickupHours(): array
    {
        return array_map(
            fn (int $hour): string => sprintf('%02d:00', $hour),
            range((int) PickupWhen::OPENS, (int) PickupWhen::CLOSES),
        );
    }

    /**
     * The pickup times the customer can still choose, as H:i => "8:00 AM".
     *
     * On today's date the hours already gone are left out, so the list never
     * offers a time that submit would refuse.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function pickupTimes(): array
    {
        $today = $this->pickup_date === now()->toDateString();

        return collect($this->pickupHours())
            ->reject(fn (string $hour): bool => $today && Carbon::createFromFormat('Y-m-d H:i', "{$this->pickup_date} {$hour}")->isPast())
            ->mapWithKeys(fn (string $hour): array => [$hour => Carbon::createFromFormat('H:i', $hour)->format('g:i A')])
            ->all();
    }

    /**
     * Check a field as soon as the customer leaves it, not only on submit.
     */
    public function updated(string $property): void
    {
        if (in_array($property, self::TRIMMED, true)) {
            $this->tidy($property);
        }

        if ($property === 'pickup_when') {
            $this->switchPickup();
        }

        // Moving to today can take the chosen hour off the list. Clear it
        // rather than keep a time the dropdown no longer shows.
        if (in_array($property, ['pickup_date', 'pickup_when'], true) && $this->pickup_time !== '' && ! array_key_exists($this->pickup_time, $this->pickupTimes)) {
            $this->pickup_time = '';
        }

        if (array_key_exists($property, $this->rules())) {
            $this->validateOnly($property);
        }
    }

    /**
     * Carry a change between today and a later day through to the cart and
     * the date: today fixes the date, and a later day drops a date that is
     * not later.
     */
    private function switchPickup(): void
    {
        $pickup = PickupWhen::tryFrom($this->pickup_when) ?? PickupWhen::Later;

        $this->pickup_when = $pickup->value;

        Session::put('cart_pickup', $pickup->value);

        if ($pickup === PickupWhen::Today) {
            $this->pickup_date = today()->toDateString();
        } elseif ($this->pickup_date !== '' && $this->pickup_date <= today()->toDateString()) {
            $this->pickup_date = '';
        }

        $this->resetValidation('pickup_date');
        unset($this->pickupTimes, $this->todayStock);
    }

    /**
     * Put a typed field in the form it is checked and stored in.
     */
    private function tidy(string $property): void
    {
        $this->{$property} = $property === 'customer_email'
            ? $this->normaliseEmail($this->{$property})
            : trim($this->{$property});
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
     * For a pickup today, how many of each size in the cart are left today at
     * the chosen outlet, as variant id => units. Empty for a later day or
     * before an outlet is chosen.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function todayStock(): array
    {
        if (! $this->forToday() || ! $this->outlets->contains('id', $this->outlet_id)) {
            return [];
        }

        return array_map(
            fn (array $outlets): int => $outlets[$this->outlet_id],
            app(CountAvailableStock::class)->handle($this->variants->pluck('id'), collect([$this->outlet_id])),
        );
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
     * Place the order — for today, or pre-ordered for a later day.
     */
    public function submit(): void
    {
        // First, before the form is even read. A browser already over the
        // limit is over it whatever the form says, and saying so plainly
        // beats failing validation on an order that was never going to be
        // accepted anyway.
        $this->throttle();

        foreach (self::TRIMMED as $property) {
            $this->tidy($property);
        }

        // A page left open past midnight still means today, not the day the
        // page was opened.
        if ($this->forToday()) {
            $this->pickup_date = today()->toDateString();
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
            if ($this->forToday()) {
                $this->ensureInStockToday((int) $validated['outlet_id'], $variants, $cart);
            }

            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
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

        app(NotifyEmployees::class)->handle(new OrderPlaced($order), 'manage-orders');

        // Counted here, on an order actually written, and never on an attempt:
        // a customer fumbling the form should not spend what a real order
        // costs.
        foreach (array_keys($this->throttles()) as $key) {
            RateLimiter::hit($key, 3600);
        }

        Session::forget(['cart', 'cart_pickup']);

        $this->redirectRoute('orders.show', ['order' => $order->reference], navigate: true);
    }

    /**
     * Refuse a pickup today that asks for more than today's stock at the
     * outlet still holds.
     *
     * The outlet row is locked first, as RecordProductStockMovement does, so
     * two customers buying the last cake at once cannot both be promised it:
     * the second waits, then counts the first one's order as a claim.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, int>  $cart
     *
     * @throws ValidationException
     */
    private function ensureInStockToday(int $outletId, Collection $variants, array $cart): void
    {
        $outlet = Outlet::query()->lockForUpdate()->findOrFail($outletId);

        $left = app(CountAvailableStock::class)->handle($variants->pluck('id'), collect([$outlet->id]));

        foreach ($variants as $variant) {
            $units = $left[$variant->id][$outlet->id];

            if ($cart[$variant->id] <= $units) {
                continue;
            }

            $name = "{$variant->product->name} · {$variant->name}";

            throw ValidationException::withMessages([
                'cart' => $units < 1
                    ? __(':name is out of stock today at :outlet. Pre-order it for a later day, or choose another outlet.', ['name' => $name, 'outlet' => $outlet->name])
                    : __('Only :count of :name are left today at :outlet.', ['count' => $units, 'name' => $name, 'outlet' => $outlet->name]),
            ]);
        }
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
        <p class="max-w-2xl text-snow-500">{{ __('Pick up today, or pre-order for a later day. No account needed — we will give you a reference to track it with.') }}</p>
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
        <section aria-labelledby="how-heading" class="flex flex-col gap-3 rounded-2xl border border-orchid-200 bg-white p-6">
            <h2 id="how-heading" class="text-lg font-semibold text-orchid-800">{{ __('How to order') }}</h2>

            <ol class="flex list-decimal flex-col gap-2 ps-5 text-sm text-snow-600">
                <li>{{ __('Check your items and how many of each you want.') }}</li>
                <li>{{ __('Fill in your name and mobile number.') }}</li>
                <li>{{ __('Choose the outlet, date and time you will pick up.') }}</li>
                <li>{{ __('Press ":button". You will get an order reference.', ['button' => $this->forToday() ? __('Place order') : __('Place pre-order')]) }}</li>
                <li>{{ __('On the next page, pay the total by GCash and send us your payment details.') }}</li>
                <li>{{ __('We check your payment and confirm your order.') }}</li>
                <li>{{ __('Show your order reference at the outlet when you pick up.') }}</li>
            </ol>
        </section>

        <div class="grid gap-8 lg:grid-cols-[1.1fr_1fr]">
            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">{{ __('Items') }}</h2>

                <ul class="flex flex-col gap-3">
                    @foreach ($this->variants as $variant)
                        <li class="flex items-center justify-between gap-4 rounded-xl border border-snow-200 bg-white p-4" wire:key="line-{{ $variant->id }}">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ $variant->product->name }}</span>
                                <span class="text-sm text-snow-500">{{ $variant->name }} &middot; &#8369;{{ number_format((float) $variant->price, 2) }}</span>
                                @if (array_key_exists($variant->id, $this->todayStock))
                                    @php($left = $this->todayStock[$variant->id])
                                    <span class="text-sm {{ $left > 0 ? 'text-green-700' : 'text-red-600' }}" data-test="left-today">
                                        {{ $left > 0 ? __(':count left today at this outlet', ['count' => $left]) : __('Out of stock today at this outlet') }}
                                    </span>
                                @endif
                            </div>

                            <div class="flex items-center gap-3">
                                <input
                                    type="number"
                                    min="1"
                                    max="99"
                                    step="1"
                                    inputmode="numeric"
                                    x-on:keydown="['e', 'E', '+', '-', '.', ','].includes($event.key) && $event.preventDefault()"
                                    x-on:input="if ($el.value > 99) $el.value = 99"
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

                <p class="text-sm text-snow-500">{{ __('You will pay by GCash on the next screen. We confirm your order once the payment is checked.') }}</p>

                <a href="{{ route('products.index') }}" class="self-start rounded-lg border border-orchid-600 px-4 py-2 text-sm font-semibold text-orchid-700 hover:bg-orchid-50" wire:navigate>
                    {{ __('Add more products') }}
                </a>
            </section>

            {{-- keep() drops what the server rules would refuse as it is typed
                 or pasted, so the customer never builds up an entry that will
                 only bounce on submit. The rules in rules() stay the authority. --}}
            <form
                wire:submit="submit"
                x-data="{ keep(el, refused) { const kept = el.value.replace(refused, ''); if (kept !== el.value) { el.value = kept } } }"
                class="flex flex-col gap-5 rounded-2xl border border-snow-200 bg-white p-6"
            >
                <h2 class="text-lg font-semibold">{{ __('Your details') }}</h2>

                <flux:input
                    wire:model.blur="customer_name"
                    :label="__('Name')"
                    maxlength="255"
                    autocomplete="name"
                    x-on:input="keep($el, /[^\p{L}\s.'-]/gu)"
                    required
                />
                <flux:input
                    wire:model.blur="customer_phone"
                    x-on:input="keep($el, /\D/g); if ($el.value.length > 11) { $el.value = $el.value.slice(0, 11); $el.dispatchEvent(new Event('input')) }"
                    :label="__('Phone')"
                    type="tel"
                    inputmode="numeric"
                    autocomplete="tel"
                    placeholder="09171234567"
                    :description="__('11-digit mobile number. We\'ll text it when your order is ready.')"
                    required
                />
                <flux:input
                    wire:model.blur="customer_email"
                    :label="__('Email')"
                    type="email"
                    maxlength="255"
                    autocomplete="email"
                    x-on:input="keep($el, /\s/g)"
                    :description="__('Optional.')"
                />

                <flux:radio.group wire:model.live="pickup_when" :label="__('When will you pick up?')" variant="segmented">
                    @foreach (\App\Enums\PickupWhen::cases() as $when)
                        <flux:radio :value="$when->value" :label="$when->label()" />
                    @endforeach
                </flux:radio.group>

                <flux:select wire:model.live="outlet_id" :label="__('Pickup outlet')" required>
                    <flux:select.option value="">{{ __('Choose where to collect') }}</flux:select.option>
                    @foreach ($this->outlets as $outlet)
                        <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid items-start gap-5 sm:grid-cols-2 lg:grid-cols-1">
                    @if ($this->forToday())
                        <flux:input wire:model="pickup_date" :label="__('Pickup date')" type="date" readonly :description:trailing="__('Today')" />
                    @else
                        <flux:input wire:model.blur="pickup_date" :label="__('Pickup date')" type="date" :min="today()->addDay()->toDateString()" required />
                    @endif
                    <flux:select
                        wire:model.blur="pickup_time"
                        :label="__('Pickup time (From 8 AM to 7 PM)')"
                        :description:trailing="$this->pickupTimes === []
                            ? __('No pickup times are left today — please pre-order for a later day.')
                            : null"
                        required
                    >
                        <flux:select.option value="">{{ __('Choose a time') }}</flux:select.option>
                        @foreach ($this->pickupTimes as $value => $label)
                            <flux:select.option :value="$value" wire:key="pickup-time-{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:textarea wire:model.blur="notes" :label="__('Notes')" rows="2" maxlength="1000" :description="__('Anything we should know — a message on the cake, for example.')" />

                <p role="note" data-test="no-refund-notice" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    {{ __('Please check your order before you place it. Once your order is confirmed, it cannot be cancelled or refunded.') }}
                </p>

                <flux:button variant="primary" type="submit">{{ $this->forToday() ? __('Place order') : __('Place pre-order') }}</flux:button>
            </form>
        </div>
    @endif
</div>
