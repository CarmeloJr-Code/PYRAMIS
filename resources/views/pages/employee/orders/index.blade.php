<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Orders')] class extends Component {
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-orders');
    }

    /**
     * The order queue — open orders first, oldest pickup first, because that is
     * the order the counter works through.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        return Order::query()
            ->with(['outlet', 'items'])
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where('status', $this->statusFilter),
                fn ($query) => $query->whereNotIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                ]),
            )
            ->orderBy('pickup_at')
            ->get();
    }

    /**
     * How many orders sit in each state, for the filter chips.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Order::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return $counts;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('Orders') }}</flux:heading>
        <flux:text>{{ __('Customer pre-orders, soonest pickup first. Open orders are shown by default.') }}</flux:text>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap gap-2">
        <flux:button
            size="sm"
            :variant="$statusFilter === '' ? 'primary' : 'ghost'"
            wire:click="$set('statusFilter', '')"
        >
            {{ __('Open') }}
        </flux:button>

        @foreach (OrderStatus::cases() as $status)
            <flux:button
                size="sm"
                wire:key="filter-{{ $status->value }}"
                :variant="$statusFilter === $status->value ? 'primary' : 'ghost'"
                wire:click="$set('statusFilter', '{{ $status->value }}')"
            >
                {{ $status->label() }}
                @if (($this->counts[$status->value] ?? 0) > 0)
                    <flux:badge size="sm" class="ms-2">{{ $this->counts[$status->value] }}</flux:badge>
                @endif
            </flux:button>
        @endforeach
    </div>

    @if ($this->orders->isEmpty())
        <flux:callout icon="inbox">
            <flux:callout.heading>{{ __('Nothing here') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No orders match this filter.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Reference') }}</flux:table.column>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Pickup') }}</flux:table.column>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell class="font-mono font-medium">{{ $order->reference }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-col">
                                    <span>{{ $order->customer_name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $order->customer_phone }}</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $order->pickup_at->format('j M, g:ia') }}</flux:table.cell>
                            <flux:table.cell>{{ $order->outlet->name }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ $order->total() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('employee.orders.show', $order)" wire:navigate>
                                    {{ __('Open') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
