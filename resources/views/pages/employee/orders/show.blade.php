<?php

use App\Actions\ConfirmOrderPayment;
use App\Actions\RecordSaleForOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Order $order;

    public string $amount = '';

    public string $gcash_reference = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(Order $order): void
    {
        Gate::authorize('manage-orders');

        $this->order = $order;
        $this->gcash_reference = $this->payment?->gcash_reference ?? '';
    }

    /**
     * The GCash payment behind this order, without the screenshot itself —
     * that is fetched by the browser from the receipt route, only if looked at.
     */
    #[Computed]
    public function payment(): ?OrderPayment
    {
        return $this->order->payment()->withoutReceipt()->with('verifier')->first();
    }

    /**
     * Check the GCash payment and, if the full amount arrived, confirm the
     * order.
     */
    public function confirmPayment(): void
    {
        Gate::authorize('manage-orders');

        $this->gcash_reference = preg_replace('/\s+/', '', $this->gcash_reference) ?? '';

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'gcash_reference' => ['required', 'regex:/^\d{13}$/'],
        ], [
            'gcash_reference.regex' => __('A GCash reference number is 13 digits.'),
        ]);

        try {
            app(ConfirmOrderPayment::class)->handle(
                $this->order,
                Auth::user(),
                (string) $validated['amount'],
                $validated['gcash_reference'],
            );
        } catch (\RuntimeException) {
            Flux::toast(variant: 'danger', text: __('That change is no longer possible for this order.'));

            return;
        }

        $this->order->refresh();
        $this->reset('amount');
        unset($this->payment);

        Flux::toast(variant: 'success', text: __('Payment checked and order confirmed.'));
    }

    /**
     * Name the tab after the reference being worked on, and make sure the lines
     * it is about to draw are in hand.
     *
     * Loaded here rather than in mount() because mount() runs once. Livewire
     * re-resolves the model from the database on every request after the first,
     * and refresh() brings back only the relations already on the model by
     * name, so a nested one is gone either way. Asking each render is what
     * keeps the item table off one query per line.
     */
    public function rendering(View $view): void
    {
        $this->order->loadMissing(['outlet', 'items.productVariant.product']);

        $view->title(__('Order :reference', ['reference' => $this->order->reference]));
    }

    /**
     * Move the order one step forward.
     *
     * Completing means the customer collected and paid, so that step also
     * records the sale — the order-to-sale workflow of the Phase 4 spec.
     */
    public function advance(): void
    {
        $next = $this->order->status->next();

        if ($next !== OrderStatus::Completed) {
            $this->apply($next);

            return;
        }

        Gate::authorize('manage-orders');

        try {
            $sale = app(RecordSaleForOrder::class)->handle($this->order, Auth::user());
        } catch (\RuntimeException) {
            Flux::toast(variant: 'danger', text: __('That change is no longer possible for this order.'));

            return;
        }

        $this->order->refresh();

        Flux::toast(
            variant: 'success',
            text: __('Order completed and sale :reference recorded.', ['reference' => $sale->reference]),
        );
    }

    /**
     * Cancel the order.
     */
    public function cancel(): void
    {
        $this->apply(OrderStatus::Cancelled);
    }

    /**
     * Apply a status change, refusing anything the workflow disallows.
     */
    protected function apply(?OrderStatus $status): void
    {
        Gate::authorize('manage-orders');

        if ($status === null) {
            return;
        }

        try {
            $this->order->transitionTo($status);
        } catch (\RuntimeException) {
            Flux::toast(variant: 'danger', text: __('That change is no longer possible for this order.'));

            return;
        }

        $this->order->refresh();

        Flux::toast(variant: 'success', text: __('Order updated to :status.', ['status' => $status->label()]));
    }
}; ?>

<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1" class="font-mono">{{ $order->reference }}</flux:heading>
            <flux:text>{{ __('Placed :date', ['date' => $order->created_at->format('j M Y, g:ia')]) }}</flux:text>
        </div>

        <flux:badge size="lg">{{ $order->status->label() }}</flux:badge>
    </div>

    <flux:separator variant="subtle" />

    <div class="grid gap-6 sm:grid-cols-3">
        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Customer') }}</span>
            <span class="font-medium">{{ $order->customer_name }}</span>
            <span class="text-sm text-zinc-500">{{ $order->customer_phone }}</span>
            @if ($order->customer_email)
                <span class="text-sm text-zinc-500">{{ $order->customer_email }}</span>
            @endif
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Pickup outlet') }}</span>
            <span class="font-medium">{{ $order->outlet->name }}</span>
            <span class="text-sm text-zinc-500">{{ $order->outlet->address }}</span>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Collect on') }}</span>
            <span class="font-medium">{{ $order->pickup_at->format('j M Y, g:ia') }}</span>
        </div>
    </div>

    @if ($order->notes)
        <div class="flex flex-col gap-1">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Customer notes') }}</span>
            <flux:text>{{ $order->notes }}</flux:text>
        </div>
    @endif

    <flux:separator variant="subtle" />

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Items') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('Size') }}</flux:table.column>
                <flux:table.column>{{ __('Qty') }}</flux:table.column>
                <flux:table.column>{{ __('Unit price') }}</flux:table.column>
                <flux:table.column>{{ __('Subtotal') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->items as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell class="font-medium">{{ $item->productVariant->product->name }}</flux:table.cell>
                        <flux:table.cell>{{ $item->productVariant->name }}</flux:table.cell>
                        <flux:table.cell>{{ $item->quantity }}</flux:table.cell>
                        <flux:table.cell>&#8369;{{ number_format((float) $item->unit_price, 2) }}</flux:table.cell>
                        <flux:table.cell>&#8369;{{ number_format($item->subtotalInCentavos() / 100, 2) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <p class="flex items-baseline justify-between border-t border-zinc-200 pt-4 text-lg dark:border-zinc-700">
            <span class="font-medium">{{ __('Total') }}</span>
            <span class="font-semibold">&#8369;{{ $order->total() }}</span>
        </p>

        <flux:text size="sm" class="text-zinc-500">
            {{ __('Prices are what the customer was quoted when they ordered, not current catalogue prices.') }}
        </flux:text>
    </div>

    <flux:separator variant="subtle" />

    @php($payment = $this->payment)

    <div class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('GCash payment') }}</flux:heading>

        @if ($payment?->isVerified())
            <flux:text>
                {{ __('Paid ₱:amount by GCash · ref :reference · checked by :name on :date', [
                    'amount' => number_format((float) $payment->amount, 2),
                    'reference' => $payment->gcash_reference,
                    'name' => $payment->verifier?->name ?? __('a former employee'),
                    'date' => $payment->verified_at->format('j M Y, g:ia'),
                ]) }}
            </flux:text>
        @elseif ($payment?->submitted_at)
            <div class="flex flex-wrap items-start gap-6">
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Customer sent') }}</span>
                    <span class="font-mono">{{ $payment->gcash_reference ?? __('No reference number') }}</span>
                    <span class="text-sm text-zinc-500">{{ $payment->submitted_at->format('j M Y, g:ia') }}</span>
                </div>

                @if ($payment->hasReceipt())
                    <a href="{{ route('employee.orders.receipt', $order) }}" target="_blank" rel="noopener" class="block">
                        <img
                            src="{{ route('employee.orders.receipt', $order) }}"
                            alt="{{ __('Screenshot of the GCash receipt') }}"
                            class="max-h-48 rounded-lg border border-zinc-200 dark:border-zinc-700"
                        >
                        <span class="text-xs text-zinc-500">{{ __('Open full size') }}</span>
                    </a>
                @endif
            </div>
        @else
            <flux:text>{{ __('The customer has not sent their payment details yet. Check your GCash app for a payment with this order reference.') }}</flux:text>
        @endif

        @if ($order->status === OrderStatus::Pending)
            <form wire:submit="confirmPayment" class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="amount"
                        :label="__('Amount received')"
                        type="number"
                        step="0.01"
                        min="0"
                        inputmode="decimal"
                        :description="__('Exactly as shown in your GCash app. Order total: ₱:total', ['total' => $order->total()])"
                        required
                    />
                    <flux:input
                        wire:model="gcash_reference"
                        :label="__('GCash reference number')"
                        inputmode="numeric"
                        maxlength="20"
                        :description="__('13 digits. Check it matches the one in your GCash app.')"
                        required
                    />
                </div>

                <flux:button variant="primary" type="submit" class="self-start">
                    {{ __('Confirm payment') }}
                </flux:button>
            </form>
        @endif
    </div>

    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-3">
        @if ($order->status->isTerminal())
            <flux:text>{{ __('This order is :status and can no longer be changed.', ['status' => $order->status->label()]) }}</flux:text>
        @else
            @unless ($order->status === OrderStatus::Pending)
                <flux:button variant="primary" wire:click="advance">
                    {{ __('Mark as :status', ['status' => $order->status->next()->label()]) }}
                </flux:button>
            @endunless

            <flux:button variant="ghost" wire:click="cancel" wire:confirm="{{ __('Cancel this order? This cannot be undone.') }}">
                {{ __('Cancel order') }}
            </flux:button>
        @endif

        <flux:spacer />

        <flux:button variant="ghost" :href="route('employee.orders.index')" wire:navigate>
            {{ __('Back to orders') }}
        </flux:button>
    </div>
</section>
