<?php

use App\Actions\VoidSale;
use App\Concerns\ReportRange;
use App\Models\Sale;
use Flux\Flux;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sales')] class extends Component {
    use WithPagination;

    /**
     * The stretches the list can cover, each a calendar one around the chosen day.
     */
    private const PERIODS = ['day', 'week', 'month'];

    private const PER_PAGE = 25;

    #[Url(as: 'date', except: '')]
    public string $date = '';

    #[Url(as: 'period', except: 'day')]
    public string $period = 'day';

    /**
     * The sale the void dialog is open on, and the reason typed into it.
     */
    public ?int $voidingId = null;

    public string $voidReason = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-sales');

        $this->date = ReportRange::day($this->date, now()->toImmutable());
        $this->keepPeriod();
    }

    /**
     * Read a cleared or mistyped day as today rather than querying with it.
     */
    public function updatedDate(): void
    {
        $this->date = ReportRange::day($this->date, now()->toImmutable());

        $this->resetPage();
    }

    /**
     * A new stretch starts back on its first page.
     */
    public function updatedPeriod(): void
    {
        $this->keepPeriod();

        $this->resetPage();
    }

    /**
     * The days covered: the chosen day, or the Monday-to-Sunday week or the
     * calendar month it falls in.
     */
    #[Computed]
    public function range(): ReportRange
    {
        $day = CarbonImmutable::parse($this->date);

        [$from, $to] = match ($this->period) {
            'week' => [$day->startOfWeek(), $day->endOfWeek()],
            'month' => [$day->startOfMonth(), $day->endOfMonth()],
            default => [$day, $day],
        };

        return ReportRange::between($from->toDateString(), $to->toDateString());
    }

    /**
     * The sales recorded over the range, newest first, a page at a time: a
     * month of them is too many to load at once.
     *
     * @return LengthAwarePaginator<int, Sale>
     */
    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        return Sale::query()
            ->with(['outlet', 'items', 'recordedBy', 'order'])
            ->whereDate('sold_at', '>=', $this->range->from())
            ->whereDate('sold_at', '<=', $this->range->to())
            ->orderByDesc('sold_at')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Total Sales over the range — the first operational metric the Phase 4
     * spec asks for. Summed in SQL across every page, and voided sales are
     * excluded: they are kept for audit, not for takings.
     */
    #[Computed]
    public function total(): string
    {
        // Grouped for reading, unlike Sale::total(), which stays a bare value.
        return number_format(Sale::takingsInCentavos($this->range->from(), $this->range->to()) / 100, 2);
    }

    /**
     * How many sales actually count towards the range's takings.
     */
    #[Computed]
    public function completedCount(): int
    {
        return Sale::countCompleted($this->range->from(), $this->range->to());
    }

    /**
     * Void a sale recorded in error. Kept, never deleted — and whatever it took
     * off the outlet's shelf goes back, since the goods never left.
     */
    public function void(int $saleId): void
    {
        Gate::authorize('access-sales');

        $sale = Sale::findOrFail($saleId);

        // Narrower than the screen: a Cashier takes back only their own sale
        // from today. Anything else goes to a manager.
        Gate::authorize('void-sale', $sale);

        // Livewire skips the TrimStrings middleware, so trim here.
        $this->voidReason = trim($this->voidReason);

        $validated = $this->validate([
            'voidReason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'voidReason.required' => __('Say why this sale is being voided.'),
        ]);

        app(VoidSale::class)->handle($sale, Auth::user(), $validated['voidReason']);

        $this->reset('voidingId', 'voidReason');

        Flux::modal('void-sale')->close();

        unset($this->sales, $this->total, $this->completedCount);
    }

    /**
     * Open the void dialog on a sale.
     */
    public function startVoid(int $saleId): void
    {
        $this->voidingId = $saleId;
        $this->voidReason = '';

        $this->resetValidation('voidReason');

        Flux::modal('void-sale')->show();
    }

    /**
     * Read anything but a known period, as an edited URL might carry, as a day.
     */
    private function keepPeriod(): void
    {
        if (! in_array($this->period, self::PERIODS, true)) {
            $this->period = 'day';
        }

        unset($this->range);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1">{{ __('Sales') }}</flux:heading>
            <flux:text>{{ __('Transactions recorded at the counter and from collected pre-orders.') }}</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-summary-report-button :report="\App\Enums\SummaryReport::Sales" />

            <flux:button :href="route('employee.sales.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('Record counter sale') }}
            </flux:button>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <flux:radio.group wire:model.live="period" :label="__('Show')" variant="segmented">
                <flux:radio value="day" :label="__('Daily')" />
                <flux:radio value="week" :label="__('Weekly')" />
                <flux:radio value="month" :label="__('Monthly')" />
            </flux:radio.group>

            <flux:input
                wire:model.live="date"
                type="date"
                :label="match ($period) { 'week' => __('Week of'), 'month' => __('Month of'), default => __('Day') }"
                class="max-w-48"
            />
        </div>

        <div class="flex flex-col items-end">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Total sales') }}</span>
            <span class="text-3xl font-semibold">&#8369;{{ $this->total }}</span>
            <span class="text-sm text-zinc-500">
                {{ $this->range->label() }} &middot;
                {{ trans_choice('{0} no transactions|{1} :count transaction|[2,*] :count transactions', $this->completedCount, ['count' => $this->completedCount]) }}
            </span>
        </div>
    </div>

    @if ($this->sales->isEmpty())
        <flux:callout icon="banknotes">
            <flux:callout.heading>{{ __('Nothing recorded') }}</flux:callout.heading>
            <flux:callout.text>
                {{ match ($period) { 'week' => __('No sales were recorded this week.'), 'month' => __('No sales were recorded this month.'), default => __('No sales were recorded on this day.') } }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->sales">
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
                            {{-- Over a week or a month the rows span days, so they say which. --}}
                            <flux:table.cell>{{ $sale->sold_at->format($period === 'day' ? 'g:ia' : 'd M, g:ia') }}</flux:table.cell>
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
                                    <flux:badge size="sm" :color="$sale->status->color()">{{ __($sale->status->label()) }}</flux:badge>
                                    @if ($sale->void_reason)
                                        <div class="mt-1 text-xs text-zinc-500">{{ $sale->void_reason }}</div>
                                    @endif
                                @elseif (auth()->user()->can('void-sale', $sale))
                                    <flux:button size="sm" variant="ghost" wire:click="startVoid({{ $sale->id }})">
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

    <flux:modal name="void-sale" class="max-w-lg">
        <form wire:submit="void($wire.voidingId)" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Void this sale?') }}</flux:heading>
                <flux:subheading>{{ __('It stays on record, with your name and the reason, but stops counting towards takings.') }}</flux:subheading>
            </div>

            <flux:input wire:model="voidReason" :label="__('Reason')" maxlength="255" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep the sale') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ __('Void sale') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
