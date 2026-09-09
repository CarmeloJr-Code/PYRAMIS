<?php

use App\Actions\BuildForecastContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Demand outlook')] class extends Component {
    #[Url(as: 'lookback', except: BuildForecastContext::LOOKBACK_DAYS)]
    public int $lookbackDays = BuildForecastContext::LOOKBACK_DAYS;

    #[Url(as: 'horizon', except: BuildForecastContext::HORIZON_DAYS)]
    public int $horizonDays = BuildForecastContext::HORIZON_DAYS;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-forecasting');
    }

    /**
     * What the records say, worked out in PHP and SQL.
     *
     * The action decides which lookbacks and horizons it will honour, so a
     * crafted query string cannot ask for a projection nobody can check.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function outlook(): array
    {
        return app(BuildForecastContext::class)->handle($this->lookbackDays, $this->horizonDays);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Demand outlook') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Read from :window, projected over the :days days to :end.', [
                    'window' => $this->outlook['window']['label'],
                    'days' => $this->outlook['horizon']['days'],
                    'end' => $this->outlook['horizon']['to'],
                ]) }}
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="lookbackDays" :label="__('Read back')" class="max-w-40">
                @foreach (App\Actions\BuildForecastContext::LOOKBACKS as $days)
                    <flux:select.option :value="$days">{{ __(':days days', ['days' => $days]) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="horizonDays" :label="__('Project ahead')" class="max-w-40">
                @foreach (App\Actions\BuildForecastContext::HORIZONS as $days)
                    <flux:select.option :value="$days">{{ __(':days days', ['days' => $days]) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{--
        Said plainly and said first. These are estimates worked out from what
        was recorded, offered to inform a decision — not predictions, and not
        instructions (BR-008, BR-009).
    --}}
    <flux:callout icon="information-circle" class="mt-6">
        <flux:callout.heading>{{ __('An estimate, not a prediction') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Every figure below is worked out from sales and stock already recorded in PYRAMIS. Nothing here changes stock, production, orders or schedules — deciding what to do about it is yours.') }}
        </flux:callout.text>
    </flux:callout>

    @php($demand = $this->outlook['demand'])
    @php($production = $this->outlook['production'])

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Sold in the window') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($demand['units_sold']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':average a day across :days days', [
                    'average' => number_format($demand['daily_average'], 2),
                    'days' => $this->outlook['window']['days'],
                ]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Recent trend') }}</span>
            <p class="mt-1 text-3xl font-semibold">
                @if ($demand['change'] === null)
                    <span class="text-zinc-400">{{ __('No comparison') }}</span>
                @else
                    <span class="{{ $demand['change'] < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ $demand['change'] >= 0 ? '+' : '' }}{{ number_format($demand['change'], 1) }}%
                    </span>
                @endif
            </p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':recent units lately against :previous before that', [
                    'recent' => number_format($demand['recent_units']),
                    'previous' => number_format($demand['previous_units']),
                ]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Projected demand') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($demand['projected_units']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __('Units over the next :days days, at ×:factor on the daily rate', [
                    'days' => $this->outlook['horizon']['days'],
                    'factor' => number_format($demand['trend_factor'], 2),
                ]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Baked in the window') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($production['units_produced']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':average a day over :runs runs', [
                    'average' => number_format($production['daily_average'], 2),
                    'runs' => number_format($production['runs']),
                ]) }}
            </p>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('The shape of a week') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Average units sold on each weekday, over the days that weekday actually fell in the window.') }}</flux:text>

    @php($busiest = collect($this->outlook['weekdays'])->max('average'))

    <div class="mt-4 grid gap-3 sm:grid-cols-7">
        @foreach ($this->outlook['weekdays'] as $weekday)
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="weekday-{{ $weekday['weekday'] }}">
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ substr($weekday["weekday"], 0, 3) }}</span>
                <p class="mt-1 text-xl font-semibold">{{ number_format($weekday['average'], 1) }}</p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                    <div
                        class="h-1.5 rounded-full bg-purple-500"
                        style="width: {{ $busiest > 0 ? round($weekday['average'] / $busiest * 100, 1) : 0 }}%"
                    ></div>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ trans_choice('{1} :count day observed|[2,*] :count days observed', $weekday['days_observed'], ['count' => $weekday['days_observed']]) }}
                </p>
            </div>
        @endforeach
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('What to consider baking') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('Projected demand against what is already made, wherever it is standing. A shortfall is a suggestion to weigh, not a production order.') }}
    </flux:text>

    @if ($this->outlook['products'] === [])
        <flux:callout icon="squares-2x2" class="mt-4">
            <flux:callout.heading>{{ __('Nothing to project') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No size is currently available for sale.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Sold') }}</flux:table.column>
                    <flux:table.column>{{ __('A day') }}</flux:table.column>
                    <flux:table.column>{{ __('Trend') }}</flux:table.column>
                    <flux:table.column>{{ __('Projected') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Shortfall') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->outlook['products'] as $row)
                        <flux:table.row wire:key="variant-{{ $row['product_variant_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['product'] }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $row['size'] }}
                                @unless ($row['has_recipe'])
                                    {{-- Without a recipe the bakery cannot log a run for it at all. --}}
                                    <flux:badge size="sm" color="zinc" class="ms-2">{{ __('No recipe') }}</flux:badge>
                                @endunless
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units_sold']) }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ number_format($row['daily_average'], 2) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['change'] === null)
                                    <span class="text-zinc-400">—</span>
                                @else
                                    <span class="{{ $row['change'] < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ $row['change'] >= 0 ? '+' : '' }}{{ number_format($row['change'], 1) }}%
                                    </span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($row['projected_units']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['stock_on_hand']) }}</flux:table.cell>
                            <flux:table.cell class="{{ $row['shortfall'] > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-500' }}">
                                {{ number_format($row['shortfall']) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:heading size="lg" class="mt-8">{{ __('What to consider ordering') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('How long the stockroom lasts at the rate the ovens have been drawing on it.') }}
    </flux:text>

    @if ($this->outlook['ingredients'] === [])
        <flux:callout icon="beaker" class="mt-4">
            <flux:callout.heading>{{ __('Nothing tracked yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No ingredient has been added to the stockroom.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Used a day') }}</flux:table.column>
                    <flux:table.column>{{ __('Needed') }}</flux:table.column>
                    <flux:table.column>{{ __('Days of cover') }}</flux:table.column>
                    <flux:table.column>{{ __('Standing') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->outlook['ingredients'] as $row)
                        <flux:table.row wire:key="ingredient-{{ $row['ingredient_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['stock'], 2) }} {{ $row['unit'] }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ number_format($row['daily_usage'], 2) }} {{ $row['unit'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['needed_for_horizon'], 2) }} {{ $row['unit'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['days_of_cover'] === null)
                                    <span class="text-zinc-400">{{ __('Not drawn on') }}</span>
                                @else
                                    {{ number_format($row['days_of_cover'], 1) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($row['below_reorder'])
                                    <flux:badge size="sm" color="red">{{ __('Below reorder level') }}</flux:badge>
                                @elseif ($row['needs_attention'])
                                    <flux:badge size="sm" color="amber">{{ __('Short for the horizon') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Covered') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <flux:text class="text-sm text-zinc-500">
        {{ __('How the projection is worked out: the daily rate over the window, multiplied by how the recent half compares with the half before it. That multiplier is held between ×0.5 and ×2.0, so one unusual stretch cannot project a rate the bakery has never run at.') }}
    </flux:text>
</section>
