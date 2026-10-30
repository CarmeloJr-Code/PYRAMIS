<?php

use App\Actions\BuildForecastContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The top of the demand outlook, on the dashboard: the sizes the projection says
 * will run short. Deterministic — the same arithmetic the forecast screen shows,
 * with no model asked — and a suggestion only: nothing here touches production
 * (BR-008, BR-009).
 *
 * Loaded lazily, after the dashboard has drawn, because the outlook reads the
 * whole lookback window and the dashboard should not wait for it.
 */
new class extends Component {
    /**
     * How many sizes the card names.
     */
    private const SHOWN = 3;

    /**
     * Forecasting is the Administrator's alone, so the component guards itself
     * rather than trusting the page that placed it.
     */
    public function mount(): void
    {
        Gate::authorize('access-forecasting');
    }

    /**
     * The days the projection covers.
     */
    #[Computed]
    public function horizonDays(): int
    {
        return BuildForecastContext::HORIZON_DAYS;
    }

    /**
     * The sizes projected to run short, largest shortfall first.
     *
     * @return list<array{product: string, size: string, shortfall: int, projected_units: int, stock_on_hand: int}>
     */
    #[Computed]
    public function shortfalls(): array
    {
        $products = app(BuildForecastContext::class)->handle()['products'];

        $short = array_filter($products, fn (array $product): bool => $product['shortfall'] > 0);

        return array_slice(array_values($short), 0, self::SHOWN);
    }
}; ?>

@placeholder
    <flux:card>
        <flux:heading>{{ __('Bake next') }}</flux:heading>
        <div class="mt-3 grid gap-2">
            <flux:skeleton class="h-10 rounded-lg" />
            <flux:skeleton class="h-10 rounded-lg" />
            <flux:skeleton class="h-10 rounded-lg" />
        </div>
    </flux:card>
@endplaceholder

<flux:card data-test="bake-next">
    <div class="flex items-center justify-between gap-2">
        <flux:heading>{{ __('Bake next') }}</flux:heading>
        <flux:button :href="route('employee.forecast')" variant="ghost" size="sm" wire:navigate>{{ __('Forecast') }}</flux:button>
    </div>

    <div class="mt-3 grid gap-1">
        @forelse ($this->shortfalls as $product)
            <div wire:key="short-{{ $loop->index }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-2">
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium">{{ $product['product'] }} &middot; {{ $product['size'] }}</span>
                    <span class="block truncate text-xs text-zinc-500">
                        {{ __('Projected :projected, :stock on hand', ['projected' => $product['projected_units'], 'stock' => $product['stock_on_hand']]) }}
                    </span>
                </span>
                <span class="shrink-0 text-sm font-semibold text-purple-600 dark:text-purple-400">
                    {{ trans_choice(':count short|:count short', $product['shortfall']) }}
                </span>
            </div>
        @empty
            <flux:text class="px-2 py-2">{{ __('Nothing short over the next :days days.', ['days' => $this->horizonDays]) }}</flux:text>
        @endforelse
    </div>

    <flux:text class="mt-2 text-xs">{{ __('From the demand outlook — a suggestion, not a production order.') }}</flux:text>
</flux:card>
