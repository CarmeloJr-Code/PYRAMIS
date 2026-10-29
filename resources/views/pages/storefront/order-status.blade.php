<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts::storefront')] class extends Component {
    use WithFileUploads;

    /**
     * How many times proof of payment may be sent for one order in an hour.
     *
     * Sending it again is allowed — a blurry screenshot, a mistyped reference —
     * but not often enough to fill the database with screenshots.
     */
    private const HOURLY_PROOF_LIMIT = 5;

    /**
     * How many times one address may send proof in an hour, across orders.
     */
    private const HOURLY_PROOF_ADDRESS_LIMIT = 20;

    #[Locked]
    public Order $order;

    public string $gcash_reference = '';

    /** @var TemporaryUploadedFile|null */
    public $receipt = null;

    /**
     * Resolved by reference, so an unknown one 404s before anything renders.
     */
    public function mount(Order $order): void
    {
        $order->load(['outlet', 'items.productVariant.product']);

        $this->order = $order;
    }

    /**
     * Name the tab after the reference.
     */
    public function rendering(View $view): void
    {
        $view->title(__('Order :reference', ['reference' => $this->order->reference]));
    }

    /**
     * Each stage of the order's journey and where the order stands against it.
     *
     * A stage is done once the order has moved past it — or reached it, for
     * the last one, since a completed order has nowhere further to go. Empty
     * for a cancelled order, which has left the line and has no place on it.
     *
     * @return list<array{status: OrderStatus, done: bool, current: bool}>
     */
    public function progress(): array
    {
        if ($this->order->status === OrderStatus::Cancelled) {
            return [];
        }

        $journey = OrderStatus::journey();
        $at = array_search($this->order->status, $journey, strict: true);
        $finished = $this->order->status === OrderStatus::Completed;

        return array_map(fn (OrderStatus $stage, int $index): array => [
            'status' => $stage,
            'done' => $index < $at || ($finished && $index === $at),
            'current' => $index === $at,
        ], $journey, array_keys($journey));
    }

    /**
     * The payment behind this order, without the screenshot itself.
     */
    #[Computed]
    public function payment(): ?OrderPayment
    {
        return $this->order->payment()->withoutReceipt()->first();
    }

    /**
     * Whether the order is still waiting for its GCash payment to be checked.
     */
    public function awaitingPayment(): bool
    {
        return $this->order->status === OrderStatus::Pending && ! ($this->payment?->isVerified() ?? false);
    }

    /**
     * Send proof of the GCash payment: the reference number, a screenshot of
     * the receipt, or both.
     *
     * The status stays Pending either way. Only a cashier checking the money
     * arrived confirms the order (ConfirmOrderPayment).
     */
    public function submitPayment(): void
    {
        $this->throttle();

        // GCash prints the reference in groups ("1234 567 890123"), so the
        // spaces a customer copies with it are not a mistake to refuse.
        $this->gcash_reference = preg_replace('/\s+/', '', $this->gcash_reference) ?? '';

        $validated = $this->validate([
            'gcash_reference' => ['nullable', 'required_without:receipt', 'regex:/^\d{13}$/'],
            'receipt' => ['nullable', 'required_without:gcash_reference', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'gcash_reference.required_without' => __('Enter your GCash reference number or attach a screenshot of the receipt.'),
            'receipt.required_without' => __('Enter your GCash reference number or attach a screenshot of the receipt.'),
            'gcash_reference.regex' => __('A GCash reference number is 13 digits.'),
            'receipt.max' => __('The screenshot must be 4 MB or smaller.'),
        ]);

        // Only what was sent this time is replaced, so a screenshot sent after
        // a reference does not wipe the reference out.
        $attributes = ['submitted_at' => now()];

        if ($validated['gcash_reference']) {
            $attributes['gcash_reference'] = $validated['gcash_reference'];
        }

        if ($this->receipt !== null) {
            $attributes['receipt'] = base64_encode($this->receipt->get());
            $attributes['receipt_mime'] = $this->receipt->getMimeType();
        }

        DB::transaction(function () use ($attributes): void {
            // Locked and re-checked, so proof cannot land on an order a cashier
            // has just confirmed or cancelled.
            $order = Order::query()->lockForUpdate()->findOrFail($this->order->id);
            $payment = $order->payment()->withoutReceipt()->first();

            if ($order->status !== OrderStatus::Pending || ($payment?->isVerified() ?? false)) {
                throw ValidationException::withMessages([
                    'payment' => __('This order is no longer waiting on payment.'),
                ]);
            }

            $order->payment()->updateOrCreate([], $attributes);
        });

        foreach (array_keys($this->throttles()) as $key) {
            RateLimiter::hit($key, 3600);
        }

        $this->reset('gcash_reference', 'receipt');
        unset($this->payment);
    }

    /**
     * The limiters proof of payment has to get past, as key => allowance.
     *
     * @return array<string, int>
     */
    private function throttles(): array
    {
        return [
            'payment-proof:order:'.$this->order->id => self::HOURLY_PROOF_LIMIT,
            'payment-proof:address:'.request()->ip() => self::HOURLY_PROOF_ADDRESS_LIMIT,
        ];
    }

    /**
     * Refuse proof that has already had its hour's worth.
     *
     * @throws ValidationException
     */
    private function throttle(): void
    {
        foreach ($this->throttles() as $key => $allowance) {
            if (! RateLimiter::tooManyAttempts($key, $allowance)) {
                continue;
            }

            throw ValidationException::withMessages([
                'payment' => __('That is more than we can take in an hour. Please try again in :minutes minutes.', [
                    'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                ]),
            ]);
        }
    }
}; ?>

<div class="flex max-w-3xl flex-col gap-8">
    <header class="flex flex-col gap-3">
        <p class="text-xs font-medium tracking-[0.2em] text-snow-400 uppercase">{{ __('Order reference') }}</p>
        <h1 class="font-mono text-3xl font-semibold tracking-tight text-orchid-800">{{ $order->reference }}</h1>
        <p class="text-snow-500">{{ __('Keep this reference — it is how you check back on your order.') }}</p>
    </header>

    @php($stages = $this->progress())

    @if ($this->awaitingPayment())
        <section aria-labelledby="pay-heading" class="flex flex-col gap-6 rounded-2xl border border-orchid-200 bg-white p-6">
            <div class="flex flex-col gap-1">
                <h2 id="pay-heading" class="text-lg font-semibold text-orchid-800">{{ __('Pay with GCash') }}</h2>
                <p class="text-sm text-snow-500">{{ __('We start on your order once we have checked your payment.') }}</p>
            </div>

            <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                <x-gcash-qr class="shrink-0" />

                <ol class="flex list-decimal flex-col gap-2 ps-5 text-sm text-snow-600">
                    <li>{{ __('Scan the code with your GCash app.') }}</li>
                    <li>
                        {{ __('Send exactly') }}
                        <span class="font-semibold text-orchid-800">&#8369;{{ $order->total() }}</span>.
                    </li>
                    <li>
                        {{ __('Put your order reference') }}
                        <span class="font-mono font-semibold">{{ $order->reference }}</span>
                        {{ __('in the message.') }}
                    </li>
                    <li>{{ __('Then give us the GCash reference number, or a screenshot of the receipt, below.') }}</li>
                </ol>
            </div>

            @if ($this->payment?->submitted_at)
                <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                    {{ __('Payment sent :date — we will confirm your order once we have checked it.', ['date' => $this->payment->submitted_at->format('j M Y, g:ia')]) }}
                    @if ($this->payment->gcash_reference)
                        <span class="block font-mono">{{ __('Reference :reference', ['reference' => $this->payment->gcash_reference]) }}</span>
                    @endif
                </p>
            @endif

            @error('payment')
                <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <form wire:submit="submitPayment" class="flex flex-col gap-5">
                @if ($this->payment?->submitted_at)
                    <p class="text-sm font-medium">{{ __('Sent the wrong thing? Send it again.') }}</p>
                @endif

                <flux:input
                    wire:model="gcash_reference"
                    :label="__('GCash reference number')"
                    inputmode="numeric"
                    maxlength="20"
                    autocomplete="off"
                    placeholder="1234 567 890123"
                    :description="__('13 digits, on your GCash receipt.')"
                />

                <div class="flex flex-col gap-2">
                    <label for="receipt" class="text-sm font-medium">{{ __('Screenshot of the receipt') }}</label>
                    <input
                        id="receipt"
                        type="file"
                        wire:model="receipt"
                        accept="image/png,image/jpeg,image/webp"
                        class="text-sm text-snow-600 file:me-3 file:rounded-lg file:border-0 file:bg-orchid-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-orchid-700 hover:file:bg-orchid-100"
                    >
                    <p class="text-xs text-snow-500">{{ __('Optional if you gave the reference number. PNG, JPG or WebP, up to 4 MB.') }}</p>
                    <p wire:loading wire:target="receipt" class="text-xs text-snow-500">{{ __('Uploading…') }}</p>
                    @error('receipt')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <flux:button variant="primary" type="submit" class="self-start" wire:loading.attr="disabled" wire:target="receipt,submitPayment">
                    {{ __('Send payment details') }}
                </flux:button>
            </form>
        </section>
    @endif

    @if ($stages === [])
        <section role="status" class="flex flex-col gap-2 rounded-2xl border border-red-200 bg-red-50 p-6">
            <div class="flex flex-wrap items-center gap-3">
                <flux:badge size="lg" color="red">{{ $order->status->label() }}</flux:badge>
                <span class="text-sm text-snow-500">{{ __('Placed :date', ['date' => $order->created_at->format('j M Y, g:ia')]) }}</span>
            </div>
            <p class="text-sm text-red-800">{{ __('This order was cancelled and will not be prepared.') }}</p>
        </section>
    @else
        <section aria-label="{{ __('Order progress') }}" class="flex flex-col gap-6 rounded-2xl border border-snow-200 bg-white p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-lg font-semibold text-orchid-800">{{ $order->status->label() }}</p>
                <p class="text-sm text-snow-500">
                    {{ __('Step :step of :total', ['step' => collect($stages)->search(fn (array $stage): bool => $stage['current']) + 1, 'total' => count($stages)]) }}
                    &middot; {{ __('Placed :date', ['date' => $order->created_at->format('j M Y, g:ia')]) }}
                </p>
            </div>

            <ol class="isolate flex">
                @foreach ($stages as $stage)
                    <li
                        class="relative flex flex-1 flex-col items-center gap-2 text-center"
                        wire:key="stage-{{ $stage['status']->value }}"
                        @if ($stage['current']) aria-current="step" @endif
                    >
                        @unless ($loop->first)
                            {{-- The track from the previous stage to this one, filled once the order gets here. --}}
                            <span aria-hidden="true" @class([
                                'absolute top-4 right-1/2 h-1 w-full -translate-y-1/2 rounded-full',
                                'bg-orchid-600' => $stage['done'] || $stage['current'],
                                'bg-snow-100' => ! ($stage['done'] || $stage['current']),
                            ])></span>
                        @endunless

                        <span @class([
                            'relative z-10 flex size-8 items-center justify-center rounded-full text-sm font-semibold',
                            'bg-orchid-600 text-white' => $stage['done'],
                            'bg-white text-orchid-700 ring-4 ring-orchid-600' => $stage['current'] && ! $stage['done'],
                            'bg-snow-100 text-snow-400' => ! $stage['done'] && ! $stage['current'],
                        ])>
                            @if ($stage['done'])
                                <flux:icon.check variant="micro" />
                            @else
                                {{ $loop->iteration }}
                            @endif
                        </span>

                        <span @class([
                            'text-xs sm:text-sm',
                            'font-semibold text-orchid-800' => $stage['current'],
                            'text-snow-600' => $stage['done'] && ! $stage['current'],
                            'text-snow-400' => ! $stage['done'] && ! $stage['current'],
                        ])>{{ $stage['status']->label() }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="grid gap-6 rounded-2xl border border-snow-200 bg-white p-6 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-[0.15em] text-snow-400 uppercase">{{ __('Pickup at') }}</span>
            <span class="font-medium">{{ $order->outlet->name }}</span>
            <span class="text-sm text-snow-500">{{ $order->outlet->address }}</span>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-[0.15em] text-snow-400 uppercase">{{ __('Collect on') }}</span>
            <span class="font-medium">{{ $order->pickup_at->format('j M Y, g:ia') }}</span>
        </div>
    </section>

    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ __('Items') }}</h2>

        <ul class="flex flex-col gap-2">
            @foreach ($order->items as $item)
                <li class="flex items-center justify-between gap-4 rounded-xl border border-snow-200 bg-white p-4" wire:key="item-{{ $item->id }}">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ $item->productVariant->product->name }}</span>
                        <span class="text-sm text-snow-500">
                            {{ $item->productVariant->name }} &middot;
                            {{ $item->quantity }} &times; &#8369;{{ number_format((float) $item->unit_price, 2) }}
                        </span>
                    </div>

                    <span class="font-semibold">&#8369;{{ number_format($item->subtotalInCentavos() / 100, 2) }}</span>
                </li>
            @endforeach
        </ul>

        <p class="flex items-baseline justify-between border-t border-snow-200 pt-4 text-lg">
            <span class="font-medium">{{ __('Total') }}</span>
            <span class="font-semibold">&#8369;{{ $order->total() }}</span>
        </p>
    </section>

    @if ($order->notes)
        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">{{ __('Your notes') }}</h2>
            <p class="rounded-xl border border-snow-200 bg-white p-4 text-snow-600">{{ $order->notes }}</p>
        </section>
    @endif
</div>
