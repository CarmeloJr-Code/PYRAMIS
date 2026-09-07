<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::storefront')] class extends Component {
    #[Locked]
    public Order $order;

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
     * The colour a status badge should take.
     */
    public function statusColor(): string
    {
        return match ($this->order->status) {
            OrderStatus::Pending => 'zinc',
            OrderStatus::Confirmed, OrderStatus::Preparing => 'blue',
            OrderStatus::Ready => 'green',
            OrderStatus::Completed => 'purple',
            OrderStatus::Cancelled => 'red',
        };
    }
}; ?>

<div class="flex max-w-3xl flex-col gap-8">
    <header class="flex flex-col gap-3">
        <p class="text-xs font-medium tracking-[0.2em] text-snow-400 uppercase">{{ __('Order reference') }}</p>
        <h1 class="font-mono text-3xl font-semibold tracking-tight text-orchid-800">{{ $order->reference }}</h1>
        <p class="text-snow-500">{{ __('Keep this reference — it is how you check back on your order.') }}</p>
    </header>

    <div class="flex flex-wrap items-center gap-3">
        <flux:badge size="lg" :color="$this->statusColor()">{{ $order->status->label() }}</flux:badge>
        <span class="text-sm text-snow-500">{{ __('Placed :date', ['date' => $order->created_at->format('j M Y, g:ia')]) }}</span>
    </div>

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
