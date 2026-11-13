<?php

use App\Actions\BuildForecastContext;
use App\Actions\CompactForecastContext;
use App\Actions\GenerateForecastReading;
use App\Ai\Agents\ForecastReadingAgent;
use App\Enums\ForecastConfidence;
use App\Enums\RecommendationPriority;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales forecast')] class extends Component {
    #[Url(as: 'lookback', except: BuildForecastContext::LOOKBACK_DAYS)]
    public int $lookbackDays = BuildForecastContext::LOOKBACK_DAYS;

    #[Url(as: 'horizon', except: BuildForecastContext::HORIZON_DAYS)]
    public int $horizonDays = BuildForecastContext::HORIZON_DAYS;

    /**
     * The reading, once a manager has asked for one.
     *
     * Held on the component rather than worked out on render, because a reading
     * costs a request against a shared allowance and is only ever produced on a
     * press. Loading this page still costs exactly what it did before.
     *
     * Locked, because only the server writes it: what is shown as the model's
     * reading must be what the model said, never something sent up the wire.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $reading = null;

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

    /**
     * Whether this server can ask for a reading at all.
     *
     * Without a key the screen says so and offers no button, rather than
     * offering one that can only fail.
     */
    #[Computed]
    public function readingAvailable(): bool
    {
        return filled(config('ai.providers.groq.key'));
    }

    /**
     * Whether the figures have moved since the reading on screen was written.
     *
     * Compares the brief as it stands now against the one the reading was made
     * from. A sale rung up in the meantime does not make the reading wrong, but
     * it does make it old, and saying so is cheaper than pretending otherwise.
     */
    #[Computed]
    public function figuresMoved(): bool
    {
        if ($this->reading === null) {
            return false;
        }

        return $this->reading['fingerprint']
            !== sha1(app(CompactForecastContext::class)->handle($this->outlook));
    }

    /**
     * Whether a recommendation names a size the bakery actually sells.
     *
     * Checking a model's output against the records is arithmetic's neighbour,
     * and it stays on our side of the line for the same reason: a name PYRAMIS
     * does not recognise should be visible on the page, not quietly trusted.
     */
    public function inCatalogue(string $item): bool
    {
        return $this->knows($item, array_map(
            fn (array $product): string => $product['product'].' '.$product['size'],
            $this->outlook['products'],
        ));
    }

    /**
     * Whether a recommendation names an ingredient the stockroom holds.
     */
    public function inStockroom(string $ingredient): bool
    {
        return $this->knows($ingredient, array_column($this->outlook['ingredients'], 'name'));
    }

    /**
     * Whether a name the model wrote points at one PYRAMIS knows.
     *
     * Forgiving about the unit or size in brackets that the brief shows
     * alongside a name, strict about the name itself.
     *
     * @param  list<string>  $known
     */
    private function knows(string $name, array $known): bool
    {
        foreach ($known as $candidate) {
            if (str_contains(mb_strtolower($name), mb_strtolower($candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask for a reading, or show the one already paid for.
     */
    public function generateReading(): void
    {
        $this->produce(refresh: false);
    }

    /**
     * Ask again, ignoring whatever is held for this window.
     */
    public function refreshReading(): void
    {
        $this->produce(refresh: true);
    }

    /**
     * Drop the reading when the window it describes changes.
     *
     * A reading of eight weeks shown beside twelve weeks of figures is worse
     * than no reading at all — it is the kind of quiet mismatch BR-008 exists
     * to prevent.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['lookbackDays', 'horizonDays'], true)) {
            $this->reading = null;
        }
    }

    /**
     * The shared path behind both buttons.
     *
     * Kept protected so the refresh flag comes from us and never off the wire.
     */
    protected function produce(bool $refresh): void
    {
        Gate::authorize('access-forecasting');

        try {
            $this->reading = app(GenerateForecastReading::class)
                ->handle($this->outlook, Auth::user(), $refresh);
        } catch (\RuntimeException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Your AI summary is ready. It\'s a suggestion, not an instruction.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sales forecast') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Based on sales from :window. Forecast for the next :days days (to :end).', [
                    'window' => $this->outlook['window']['label'],
                    'days' => $this->outlook['horizon']['days'],
                    'end' => \Carbon\CarbonImmutable::parse($this->outlook['horizon']['to'])->format('d M Y'),
                ]) }}
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="lookbackDays" :label="__('Look back')" class="max-w-40">
                @foreach (App\Actions\BuildForecastContext::LOOKBACKS as $days)
                    <flux:select.option :value="$days">{{ __(':days days', ['days' => $days]) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="horizonDays" :label="__('Look ahead')" class="max-w-40">
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
        <flux:callout.heading>{{ __('This is an estimate, not a guarantee') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('These numbers come from your past sales and stock. Nothing on this page changes stock, baking, orders or schedules. You decide what to do.') }}
        </flux:callout.text>
    </flux:callout>

    @php($demand = $this->outlook['demand'])
    @php($production = $this->outlook['production'])

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Sold (last :days days)', ['days' => $this->outlook['window']['days']]) }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($demand['units_sold']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':average per day', ['average' => number_format($demand['daily_average'], 2)]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Going up or down') }}</span>
            <p class="mt-1 text-3xl font-semibold">
                @if ($demand['change'] === null)
                    <span class="text-zinc-400">{{ __('Not enough data') }}</span>
                @else
                    <x-change :value="$demand['change']">
                        {{ $demand['change'] >= 0 ? '+' : '' }}{{ number_format($demand['change'], 1) }}%
                    </x-change>
                @endif
            </p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':recent lately, :previous before that', [
                    'recent' => number_format($demand['recent_units']),
                    'previous' => number_format($demand['previous_units']),
                ]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Expected sales') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($demand['projected_units']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __('Over the next :days days', ['days' => $this->outlook['horizon']['days']]) }}
            </p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Baked (last :days days)', ['days' => $this->outlook['window']['days']]) }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($production['units_produced']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __(':average per day, from :runs bakes', [
                    'average' => number_format($production['daily_average'], 2),
                    'runs' => number_format($production['runs']),
                ]) }}
            </p>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Busiest days of the week') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Average number sold on each day of the week.') }}</flux:text>

    @php($busiest = collect($this->outlook['weekdays'])->max('average'))

    <div class="mt-4 grid gap-3 sm:grid-cols-7">
        @foreach ($this->outlook['weekdays'] as $weekday)
            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="weekday-{{ $weekday['weekday'] }}">
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ substr($weekday["weekday"], 0, 3) }}</span>
                <p class="mt-1 text-xl font-semibold">{{ number_format($weekday['average'], 1) }}</p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                    <div
                        class="h-1.5 rounded-full bg-orchid-500"
                        style="width: {{ $busiest > 0 ? round($weekday['average'] / $busiest * 100, 1) : 0 }}%"
                    ></div>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ trans_choice('{1} :count day counted|[2,*] :count days counted', $weekday['days_observed'], ['count' => $weekday['days_observed']]) }}
                </p>
            </div>
        @endforeach
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Products that may run short') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('Expected sales compared with what is already baked. This is a suggestion, not a baking order.') }}
    </flux:text>

    @if ($this->outlook['products'] === [])
        <flux:callout icon="squares-2x2" class="mt-4">
            <flux:callout.heading>{{ __('Nothing to forecast') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No product is for sale right now.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Sold') }}</flux:table.column>
                    <flux:table.column>{{ __('Per day') }}</flux:table.column>
                    <flux:table.column>{{ __('Change') }}</flux:table.column>
                    <flux:table.column>{{ __('Expected') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Short by') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->outlook['products'] as $row)
                        <flux:table.row wire:key="variant-{{ $row['product_variant_id'] }}">
                            <flux:table.cell class="font-medium">{{ $row['product'] }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $row['size'] }}
                                @unless ($row['has_recipe'])
                                    {{-- Without an approved recipe the bakery cannot log a run for it at all. --}}
                                    <flux:badge size="sm" color="amber" class="ms-2">{{ __('No approved recipe') }}</flux:badge>
                                @endunless
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($row['units_sold']) }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ number_format($row['daily_average'], 2) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['change'] === null)
                                    <span class="text-zinc-400">—</span>
                                @else
                                    <x-change :value="$row['change']">
                                        {{ $row['change'] >= 0 ? '+' : '' }}{{ number_format($row['change'], 1) }}%
                                    </x-change>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($row['projected_units']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['stock_on_hand']) }}</flux:table.cell>
                            <flux:table.cell class="{{ $row['shortfall'] > 0 ? 'font-semibold text-warning' : 'text-zinc-500' }}">
                                {{ number_format($row['shortfall']) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:heading size="lg" class="mt-8">{{ __('Ingredients that may run out') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('How many days each ingredient will last at the current rate of use.') }}
    </flux:text>

    @if ($this->outlook['ingredients'] === [])
        <flux:callout icon="beaker" class="mt-4">
            <flux:callout.heading>{{ __('Nothing tracked yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No ingredients have been added yet.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                    <flux:table.column>{{ __('In stock') }}</flux:table.column>
                    <flux:table.column>{{ __('Used per day') }}</flux:table.column>
                    <flux:table.column>{{ __('Needed') }}</flux:table.column>
                    <flux:table.column>{{ __('Days left') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
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
                                    <span class="text-zinc-400">{{ __('Not used yet') }}</span>
                                @else
                                    {{ number_format($row['days_of_cover'], 1) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($row['below_reorder'])
                                    <flux:badge size="sm" color="red">{{ __('Reorder now') }}</flux:badge>
                                @elseif ($row['needs_attention'])
                                    <flux:badge size="sm" color="amber">{{ __('Not enough') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">{{ __('Enough') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <flux:heading size="lg">{{ __('AI summary') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('AI can read the numbers above and point out what stands out. It only runs when you press the button.') }}
    </flux:text>

    @if (! $this->readingAvailable)
        <flux:callout icon="sparkles" class="mt-4">
            <flux:callout.heading>{{ __('AI summary is turned off') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('No AI key is set up on this server. The numbers above still work. They don\'t need AI.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        @if ($reading === null)
            <flux:button variant="primary" icon="sparkles" wire:click="generateReading" class="mt-4">
                {{ __('Get AI summary') }}
            </flux:button>
        @endif

        {{--
            A multi-second call to something outside PYRAMIS, which nothing else
            in the app makes. The screen says what it is waiting for rather than
            going quiet. flux:button handles its own spinner, so the target here
            is named explicitly to keep the lookback select from firing it.
        --}}
        <div wire:loading.flex wire:target="generateReading, refreshReading" class="mt-4">
            <flux:callout icon="sparkles" class="w-full animate-pulse">
                <flux:callout.heading>{{ __('Writing the summary') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('This takes a few seconds. The numbers above won\'t change.') }}
                </flux:callout.text>
            </flux:callout>
        </div>

        @if ($reading !== null)
            @php($confidence = ForecastConfidence::fromModel($reading['confidence']))

            <div wire:loading.class="opacity-40" wire:target="refreshReading">
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                    <flux:callout.heading>{{ __('Written by AI') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('This is the AI\'s opinion, not a prediction and not a decision. It can be wrong. It has not changed anything: no stock moved, no bake was logged, no order was placed. You decide what to do.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <flux:badge :color="$confidence->color()">
                        {{ __('How sure it is: :label', ['label' => $confidence->label()]) }}
                    </flux:badge>

                    <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="refreshReading">
                        {{ __('Refresh') }}
                    </flux:button>
                </div>

                @if ($this->figuresMoved)
                    <flux:text class="mt-2 text-sm text-warning">
                        {{ __('New records have come in since this was written, so it may be out of date.') }}
                    </flux:text>
                @endif

                <flux:text class="mt-4">{{ $reading['summary'] }}</flux:text>

                @if ($reading['key_factors'] !== [])
                    <flux:heading size="sm" class="mt-6">{{ __('Key points') }}</flux:heading>
                    <dl class="mt-2 space-y-3">
                        @foreach ($reading['key_factors'] as $factor)
                            <div>
                                <dt class="text-sm font-medium">{{ $factor['factor'] }}</dt>
                                <dd class="text-sm text-zinc-500">{{ $factor['evidence'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <flux:heading size="sm" class="mt-6">{{ __('Baking ideas') }}</flux:heading>

                @if ($reading['production_recommendations'] === [])
                    <flux:text class="mt-2 text-sm text-zinc-500">
                        {{ __('No baking ideas this time.') }}
                    </flux:text>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Size') }}</flux:table.column>
                                <flux:table.column>{{ __('Idea') }}</flux:table.column>
                                <flux:table.column>{{ __('Why') }}</flux:table.column>
                                <flux:table.column>{{ __('Priority') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($reading['production_recommendations'] as $row)
                                    @php($priority = RecommendationPriority::fromModel($row['priority']))
                                    <flux:table.row>
                                        <flux:table.cell>
                                            {{ $row['item'] }}
                                            @unless ($this->inCatalogue($row['item']))
                                                <flux:badge size="sm" color="zinc">{{ __('Not a product we sell') }}</flux:badge>
                                            @endunless
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $row['action'] }}</flux:table.cell>
                                        <flux:table.cell class="text-zinc-500">{{ $row['reason'] }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge size="sm" :color="$priority->color()">{{ $priority->label() }}</flux:badge>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <flux:heading size="sm" class="mt-6">{{ __('Ingredient ideas') }}</flux:heading>

                @if ($reading['inventory_recommendations'] === [])
                    <flux:text class="mt-2 text-sm text-zinc-500">
                        {{ __('No ingredient ideas this time.') }}
                    </flux:text>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                                <flux:table.column>{{ __('Idea') }}</flux:table.column>
                                <flux:table.column>{{ __('Why') }}</flux:table.column>
                                <flux:table.column>{{ __('Priority') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($reading['inventory_recommendations'] as $row)
                                    @php($priority = RecommendationPriority::fromModel($row['priority']))
                                    <flux:table.row>
                                        <flux:table.cell>
                                            {{ $row['ingredient'] }}
                                            @unless ($this->inStockroom($row['ingredient']))
                                                <flux:badge size="sm" color="zinc">{{ __('Not an ingredient we stock') }}</flux:badge>
                                            @endunless
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $row['action'] }}</flux:table.cell>
                                        <flux:table.cell class="text-zinc-500">{{ $row['reason'] }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge size="sm" :color="$priority->color()">{{ $priority->label() }}</flux:badge>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <flux:text class="mt-4 text-sm text-zinc-500">
                    {{ __('Written by :model on :time.', [
                        'model' => $reading['model'],
                        'time' => \Carbon\CarbonImmutable::parse($reading['generated_at'])->format('j M Y, g:ia'),
                    ]) }}
                </flux:text>
            </div>
        @endif
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <flux:text class="text-sm text-zinc-500">
        {{ __('How the forecast works: we take the average sold per day, then nudge it up or down depending on whether sales rose or fell lately. The nudge is capped (between half and double), so one odd week can\'t throw it off.') }}
    </flux:text>
</section>
