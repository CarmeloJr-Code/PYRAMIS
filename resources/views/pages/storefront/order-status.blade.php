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
}; ?>

<div class="flex max-w-3xl flex-col gap-8">
    <header class="flex flex-col gap-3">
        <p class="text-xs font-medium tracking-[0.2em] text-snow-400 uppercase">{{ __('Order reference') }}</p>
        <h1 class="font-mono text-3xl font-semibold tracking-tight text-orchid-800">{{ $order->reference }}</h1>
        <p class="text-snow-500">{{ __('Keep this reference — it is how you check back on your order.') }}</p>
    </header>

    @php($stages = $this->progress())

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

            <ol class="flex">
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
                            'relative flex size-8 items-center justify-center rounded-full text-sm font-semibold',
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
