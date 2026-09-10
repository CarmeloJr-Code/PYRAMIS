<?php

use App\Actions\VoidSale;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales')] class extends Component {
    #[Url(as: 'date', except: '')]
    public string $date = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-sales');

        if ($this->date === '') {
            $this->date = now()->toDateString();
        }
    }

    /**
     * The sales recorded on the chosen day, newest first.
     *
     * @return Collection<int, Sale>
     */
    #[Computed]
    public function sales(): Collection
    {
        return Sale::query()
            ->with(['outlet', 'items', 'recordedBy', 'order'])
            ->whereDate('sold_at', $this->date)
            ->orderByDesc('sold_at')
            ->get();
    }

    /**
     * Total Sales for the day — the first operational metric the Phase 4 spec
     * asks for. Voided sales are excluded: they are kept for audit, not for
     * takings.
     */
    #[Computed]
    public function dailyTotal(): string
    {
        $centavos = $this->sales
            ->reject(fn (Sale $sale): bool => $sale->isVoided())
            ->sum(fn (Sale $sale): int => $sale->totalInCentavos());

        // Grouped for reading, unlike Sale::total(), which stays a bare value.
        return number_format($centavos / 100, 2);
    }

    /**
     * How many sales actually count towards the day's takings.
     */
    #[Computed]
    public function completedCount(): int
    {
        return $this->sales->reject(fn (Sale $sale): bool => $sale->isVoided())->count();
    }

    /**
     * Void a sale recorded in error. Kept, never deleted — and whatever it took
     * off the outlet's shelf goes back, since the goods never left.
     */
    public function void(int $saleId): void
    {
        Gate::authorize('access-sales');

        app(VoidSale::class)->handle(Sale::findOrFail($saleId), Auth::user());

        unset($this->sales, $this->dailyTotal, $this->completedCount);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1">{{ __('Sales') }}</flux:heading>
            <flux:text>{{ __('Transactions recorded at the counter and from collected pre-orders.') }}</flux:text>
        </div>

        <flux:button :href="route('employee.sales.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('Record counter sale') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <flux:input
            wire:model.live="date"
            type="date"
            :label="__('Day')"
            class="max-w-48"
        />

        <div class="flex flex-col items-end">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Total sales') }}</span>
            <span class="text-3xl font-semibold">&#8369;{{ $this->dailyTotal }}</span>
            <span class="text-sm text-zinc-500">
                {{ trans_choice('{1} :count transaction|[2,*] :count transactions', $this->completedCount, ['count' => $this->completedCount]) }}
            </span>
        </div>
    </div>

    @if ($this->sales->isEmpty())
        <flux:callout icon="banknotes">
            <flux:callout.heading>{{ __('Nothing recorded') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No sales were recorded on this day.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Sale') }}</flux:table.column>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->sales as $sale)
                        <flux:table.row :key="$sale->id">
                            <flux:table.cell class="font-mono font-medium">{{ $sale->reference }}</flux:table.cell>
                            <flux:table.cell>{{ $sale->sold_at->format('g:ia') }}</flux:table.cell>
                            <flux:table.cell>{{ $sale->outlet->name }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($sale->order)
                                    <span class="font-mono text-sm">{{ $sale->order->reference }}</span>
                                @else
                                    <span class="text-zinc-500">{{ __('Counter') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $sale->recordedBy->name }}</flux:table.cell>
                            <flux:table.cell class="{{ $sale->isVoided() ? 'text-zinc-400 line-through' : '' }}">
                                &#8369;{{ number_format($sale->totalInCentavos() / 100, 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                @if ($sale->isVoided())
                                    <flux:badge size="sm" color="red">{{ __('Voided') }}</flux:badge>
                                @else
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="void({{ $sale->id }})"
                                        wire:confirm="{{ __('Void this sale? It stays on record but stops counting towards takings.') }}"
                                    >
                                        {{ __('Void') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
