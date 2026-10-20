<?php

use App\Models\Order;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::storefront')] #[Title('Track your order')] class extends Component {
    /**
     * How many references one browser may get wrong in an hour.
     *
     * A customer mistyping their own reference gets it right within a few
     * tries. Ten leaves room for that and nothing like room to guess.
     */
    private const HOURLY_MISS_LIMIT = 10;

    /**
     * How many references one address may get wrong in an hour.
     *
     * Looser than the browser limit for the same reason the order form's is:
     * an address here can be a whole neighbourhood behind one carrier.
     */
    private const HOURLY_ADDRESS_MISS_LIMIT = 50;

    public string $reference = '';

    /**
     * Send the customer to their order, if the reference is one.
     */
    public function submit(): void
    {
        // First, so a browser already over the limit learns nothing more,
        // not even whether this guess would have been right.
        $this->throttle();

        $this->validate([
            'reference' => ['required', 'string', 'max:30'],
        ]);

        $reference = $this->normalise($this->reference);

        if (! Order::where('reference', $reference)->exists()) {
            // Counted on a miss only: a customer checking back on a real
            // order as often as they like costs nothing.
            foreach (array_keys($this->throttles()) as $key) {
                RateLimiter::hit($key, 3600);
            }

            throw ValidationException::withMessages([
                'reference' => __('We could not find an order with that reference. Please check it and try again.'),
            ]);
        }

        $this->redirectRoute('orders.show', ['order' => $reference], navigate: true);
    }

    /**
     * Put a reference the way it is stored, however it was typed.
     *
     * Customers read references aloud and copy them by hand, so case, spaces
     * and the dash are forgiven, and the PY prefix may be left off.
     */
    private function normalise(string $input): string
    {
        $compact = Str::upper(preg_replace('/[\s-]+/', '', $input));

        if (strlen($compact) === 12 && str_starts_with($compact, 'PY')) {
            $compact = substr($compact, 2);
        }

        return 'PY-'.$compact;
    }

    /**
     * The limiters a lookup has to get past, as key => allowance.
     *
     * Both the browser and the address, for the reasons the order form gives:
     * neither is an identity on its own (BR-001).
     *
     * @return array<string, int>
     */
    private function throttles(): array
    {
        return [
            'track-order:session:'.Session::getId() => self::HOURLY_MISS_LIMIT,
            'track-order:address:'.request()->ip() => self::HOURLY_ADDRESS_MISS_LIMIT,
        ];
    }

    /**
     * Refuse a lookup from somewhere that has already missed too often.
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
                'reference' => __('Too many references that did not match. Please try again in :minutes minutes.', [
                    'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                ]),
            ]);
        }
    }
}; ?>

<div class="flex max-w-xl flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('Track your order') }}</h1>
        <p class="text-snow-500">{{ __('Enter the reference you were given when you placed your pre-order, for example PY-ABC234DEF5.') }}</p>
    </header>

    <form wire:submit="submit" class="flex flex-col gap-5 rounded-2xl border border-snow-200 bg-white p-6">
        <flux:input
            wire:model="reference"
            :label="__('Order reference')"
            placeholder="PY-"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            required
        />

        <flux:button variant="primary" type="submit">{{ __('Track order') }}</flux:button>
    </form>
</div>
