<?php

use App\Enums\RestockStatus;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Restock;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Schedule restock')] class extends Component {
    public ?int $outlet_id = null;

    public string $scheduled_for = '';

    public string $notes = '';

    /**
     * What is being sent.
     *
     * @var array<int, array{product_variant_id: int|null, quantity: string}>
     */
    public array $lines = [];

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('schedule-restocks');

        $this->scheduled_for = now()->toDateString();
        $this->outlet_id = $this->destinations->first()?->id;

        $this->addLine();
    }

    /**
     * The outlets goods can be sent to.
     *
     * The main branch is excluded: it is the source, and sending stock to
     * itself would be a transfer with no destination (BR-004).
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function destinations(): Collection
    {
        return Outlet::query()
            ->active()
            ->where('is_main_branch', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * Everything on the main branch's shelf, with how much of it there is.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function available(): Collection
    {
        $mainBranch = Outlet::query()->where('is_main_branch', true)->first();

        if ($mainBranch === null) {
            return collect();
        }

        return ProductVariant::query()
            ->withStockAt($mainBranch)
            ->with('product')
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->name)
            ->values();
    }

    /**
     * Append an empty line.
     */
    public function addLine(): void
    {
        $this->lines[] = ['product_variant_id' => null, 'quantity' => ''];
    }

    /**
     * Drop a line. A restock must keep at least one.
     */
    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);

        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    /**
     * Schedule the restock.
     *
     * Nothing moves here. Stock leaves the main branch on delivery, because
     * until then the goods are still on its shelf.
     */
    public function save(): void
    {
        Gate::authorize('schedule-restocks');

        $validated = $this->validate([
            'outlet_id' => [
                'required', 'integer',
                // A closure rather than two where() calls: the rule's string
                // form renders a false value as an empty string, which no
                // boolean column ever matches.
                Rule::exists('outlets', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->where('is_main_branch', false),
                ),
            ],
            'scheduled_for' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('product_variants', 'id'),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $restock = DB::transaction(function () use ($validated): Restock {
            $restock = Restock::create([
                'outlet_id' => $validated['outlet_id'],
                'status' => RestockStatus::Requested,
                'scheduled_for' => $validated['scheduled_for'],
                'requested_by' => Auth::id(),
                'notes' => $validated['notes'] ?: null,
            ]);

            foreach ($validated['lines'] as $line) {
                $restock->items()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity_requested' => $line['quantity'],
                ]);
            }

            return $restock;
        });

        Flux::toast(variant: 'success', text: __('Restock :reference scheduled.', ['reference' => $restock->reference]));

        $this->redirectRoute('employee.restocks.show', $restock, navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Schedule restock') }}</flux:heading>
    <flux:text class="mt-2">{{ __('What the main branch should send an outlet, and when. Stock moves when it is delivered, not now.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->destinations->isEmpty())
        <flux:callout icon="building-storefront">
            <flux:callout.heading>{{ __('Nowhere to send to') }}</flux:callout.heading>
            <flux:callout.text>{{ __('There is no open outlet besides the main branch.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:select wire:model="outlet_id" :label="__('Outlet')" required>
                <flux:select.option value="">{{ __('Choose an outlet') }}</flux:select.option>
                @foreach ($this->destinations as $outlet)
                    <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="scheduled_for" :label="__('Scheduled for')" type="date" required />

            <flux:separator variant="subtle" />

            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('What to send') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addLine">
                        {{ __('Add size') }}
                    </flux:button>
                </div>

                @error('lines')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                @foreach ($lines as $index => $line)
                    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="line-{{ $index }}">
                        <flux:select wire:model="lines.{{ $index }}.product_variant_id" :label="__('Size')" class="flex-1">
                            <flux:select.option value="">{{ __('Choose a size') }}</flux:select.option>
                            @foreach ($this->available as $variant)
                                <flux:select.option :value="$variant->id">
                                    {{ $variant->product->name }} &middot; {{ $variant->name }}
                                    ({{ $variant->stockInUnits() }} {{ __('at the main branch') }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            wire:model="lines.{{ $index }}.quantity"
                            :label="__('Units')"
                            type="number"
                            min="1"
                            class="md:w-32"
                        />

                        <div class="md:pt-7">
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="trash"
                                type="button"
                                wire:click="removeLine({{ $index }})"
                                :disabled="count($lines) === 1"
                            />
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:input wire:model="notes" :label="__('Note (optional)')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Schedule restock') }}</flux:button>
                <flux:button variant="ghost" :href="route('employee.restocks.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
