<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Orders')] class extends Component {
    use WithPagination;

    /**
     * How many orders a page holds.
     *
     * Enough that the open queue almost always fits on one, so the counter's
     * everyday view is unchanged.
     */
    private const PER_PAGE = 25;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The pickup day being looked at, as Y-m-d — empty for every day.
     */
    #[Url(as: 'day', except: '')]
    public string $pickupDay = '';

    /**
     * The product size being looked at — 0 for every one.
     */
    #[Url(as: 'size', except: 0)]
    public int $variantId = 0;

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
     * Paged. Left to itself the default view is small, but a status filter asks
     * for every completed or cancelled order the shop has ever taken, and that
     * list only grows.
     *
     * Two pickups can fall on the same minute, so the id breaks the tie: without
     * a total order the database is free to return equal rows in any order it
     * likes, and a row can appear on two pages or on none.
     *
     * A search looks past the open queue unless a status was picked, so a
     * customer whose order was already collected or cancelled is shown that,
     * not told it does not exist.
     *
     * A pickup day and a product size narrow whatever the rest picked, so every
     * order of one size due on one day can be gathered together.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $term = $this->term();
        $day = $this->day();

        return Order::query()
            ->with(['outlet', 'items'])
            // Proof sent and nobody has checked it yet: the orders the counter
            // can move on right now.
            ->withExists(['payment as payment_to_check' => fn ($query) => $query
                ->whereNotNull('submitted_at')
                ->whereNull('verified_at')])
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where('status', $this->statusFilter),
                fn ($query) => $query->when($term === '', fn ($query) => $query->whereNotIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                ])),
            )
            ->when($term !== '', fn ($query) => $query->where(function ($match) use ($term): void {
                $match->whereLike('customer_name', "%{$term}%");

                // Every reference starts with PY, so the prefix alone matches
                // nothing in particular.
                if (! in_array($fragment = $this->referenceFragment($term), ['', 'PY'], true)) {
                    $match->orWhereLike('reference', "%{$fragment}%");
                }

                // Only a term that is all number is a phone number — otherwise
                // the digits in "PY-234" would match every phone containing 234.
                // Orders taken before the form insisted on 09XXXXXXXXX may hold
                // spaces or dashes, so those are ignored on both sides.
                if (preg_match('/^[\d\s+-]+$/', $term) === 1 && strlen($digits = preg_replace('/\D/', '', $term)) >= 3) {
                    $match->orWhereRaw("replace(replace(customer_phone, ' ', ''), '-', '') like ?", ["%{$digits}%"]);
                }
            }))
            ->when($day !== null, fn ($query) => $this->pickedUpOn($query, $day))
            ->when($this->variantId > 0, fn ($query) => $query->whereHas(
                'items',
                fn ($items) => $items->where('product_variant_id', $this->variantId),
            ))
            ->orderBy('pickup_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Every product size, for the size picker — by product, then size.
     *
     * Unavailable sizes stay in: an order taken before a size was withdrawn
     * still has to be found.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function variants(): Collection
    {
        return ProductVariant::query()
            ->with('product')
            ->get()
            ->sortBy([
                fn (ProductVariant $a, ProductVariant $b): int => $a->product->name <=> $b->product->name,
                fn (ProductVariant $a, ProductVariant $b): int => $a->name <=> $b->name,
            ])
            ->values();
    }

    /**
     * How many of each size are due on the picked day, and on how many orders.
     *
     * The whole day under the picked status, whatever the search or size: it is
     * what the size picker is chosen from. Nothing when no day is picked.
     *
     * @return Collection<int, OrderItem>
     */
    #[Computed]
    public function tally(): Collection
    {
        $day = $this->day();

        if ($day === null) {
            return collect();
        }

        return OrderItem::query()
            ->with('productVariant.product')
            ->selectRaw('product_variant_id, sum(quantity) as quantity, count(distinct order_id) as order_count')
            ->whereHas('order', function (Builder $query) use ($day): void {
                $this->pickedUpOn($query, $day);

                $this->statusFilter !== ''
                    ? $query->where('status', $this->statusFilter)
                    : $query->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value]);
            })
            ->groupBy('product_variant_id')
            ->get()
            ->sortBy([
                fn (OrderItem $a, OrderItem $b): int => $a->productVariant->product->name <=> $b->productVariant->product->name,
                fn (OrderItem $a, OrderItem $b): int => $a->productVariant->name <=> $b->productVariant->name,
            ])
            ->values();
    }

    /**
     * Narrow orders to those picked up on the given day.
     *
     * A range rather than a date function, so an index on pickup_at can serve
     * it and SQLite and Postgres read it alike.
     *
     * @param  Builder<Order>  $query
     */
    private function pickedUpOn(Builder $query, CarbonImmutable $day): void
    {
        $query->whereBetween('pickup_at', [$day->startOfDay(), $day->endOfDay()]);
    }

    /**
     * The picked pickup day, or null for every day.
     *
     * The URL is editable and a cleared date field sends an empty string, so
     * anything that is not a real Y-m-d date means no day was picked.
     */
    private function day(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->pickupDay) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $this->pickupDay);

        return $day !== false && $day->toDateString() === $this->pickupDay ? $day : null;
    }

    /**
     * A different filter is a different list, so it starts at the top.
     *
     * Without this, filtering while on page four asks for page four of the new
     * list, which is usually empty.
     */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * A different search is a different list too.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * So does a different pickup day.
     */
    public function updatedPickupDay(): void
    {
        $this->resetPage();
    }

    /**
     * And a different product size.
     */
    public function updatedVariantId(): void
    {
        $this->resetPage();
    }

    /**
     * Drop the pickup day and size, back to orders of every size on every day.
     */
    public function clearPickupFilters(): void
    {
        $this->reset('pickupDay', 'variantId');
        $this->resetPage();
    }

    /**
     * What the cashier typed, ready to sit inside a LIKE pattern.
     *
     * LIKE wildcards are dropped rather than escaped, as on the storefront menu:
     * SQLite and Postgres disagree on the default escape character.
     */
    private function term(): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['%', '_', '\\'], ' ', $this->search)) ?? '');
    }

    /**
     * A reference as it is stored, however much of it was read out.
     *
     * Case, spaces and the dash are forgiven. The PY prefix is dropped only when
     * it is plainly the prefix — "PY-", "PY " or a whole reference — so a name
     * like "Pyramid" is not cut down to "RAMID".
     */
    private function referenceFragment(string $term): string
    {
        $compact = Str::upper(preg_replace('/[\s-]+/', '', $term) ?? '');

        if (preg_match('/^py[\s-]/i', $term) === 1 || (strlen($compact) === 12 && str_starts_with($compact, 'PY'))) {
            $compact = substr($compact, 2);
        }

        return $compact;
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
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1">{{ __('Orders') }}</flux:heading>
            <flux:text>{{ __('Customer pre-orders, earliest pickup first. Open orders show by default.') }}</flux:text>
        </div>

        <x-summary-report-button :report="\App\Enums\SummaryReport::Orders" />
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-4 max-w-md">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Reference, name or phone')"
            :aria-label="__('Search orders by reference, name or phone')"
        />
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-4">
        <flux:input wire:model.live="pickupDay" type="date" :label="__('Pickup day')" class="max-w-48" />

        <flux:select wire:model.live="variantId" :label="__('Product and size')" class="max-w-72">
            <flux:select.option value="0">{{ __('All products') }}</flux:select.option>
            @foreach ($this->variants as $variant)
                <flux:select.option :value="$variant->id" wire:key="size-{{ $variant->id }}">{{ $variant->product->name }} · {{ $variant->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($pickupDay !== '' || $variantId > 0)
            <flux:button size="sm" variant="ghost" wire:click="clearPickupFilters">{{ __('Clear') }}</flux:button>
        @endif
    </div>

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

    @if ($this->tally->isNotEmpty())
        <div class="mb-6" data-test="pickup-tally">
            <flux:heading size="lg" level="2" class="mb-2">
                {{ __('Due for pickup on :day', ['day' => \Carbon\CarbonImmutable::parse($pickupDay)->format('j M')]) }}
            </flux:heading>

            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Product') }}</flux:table.column>
                        <flux:table.column>{{ __('Size') }}</flux:table.column>
                        <flux:table.column>{{ __('Quantity') }}</flux:table.column>
                        <flux:table.column>{{ __('Orders') }}</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->tally as $line)
                            <flux:table.row
                                :key="'tally-'.$line->product_variant_id"
                                :class="$line->product_variant_id === $variantId ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                            >
                                <flux:table.cell>{{ $line->productVariant->product->name }}</flux:table.cell>
                                <flux:table.cell>{{ $line->productVariant->name }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $line->quantity }}</flux:table.cell>
                                <flux:table.cell>{{ (int) $line->order_count }}</flux:table.cell>
                                <flux:table.cell class="text-end">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="$set('variantId', {{ $line->product_variant_id }})"
                                    >
                                        {{ __('Show these') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    @if ($this->orders->isEmpty() && trim($search) !== '')
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>{{ __('No orders match “:search”.', ['search' => $search]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Check the reference with the customer, or search by their name or phone.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="$set('search', '')">{{ __('Clear the search') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->orders->isEmpty())
        <flux:callout icon="inbox">
            <flux:callout.heading>{{ __('Nothing here') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No orders match this filter.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->orders">
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
                                <flux:badge size="sm" :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
                                @if ($order->status === OrderStatus::Pending && $order->payment_to_check)
                                    <flux:badge size="sm" color="amber" class="ms-1">{{ __('Payment to check') }}</flux:badge>
                                @endif
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
