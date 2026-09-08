<?php

use App\Actions\RecordInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Ingredient $ingredient;

    public string $type = InventoryMovementType::Received->value;

    public string $quantity = '';

    /**
     * Which way an adjustment goes. A receipt is always stock arriving.
     */
    public string $direction = 'in';

    public string $note = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(Ingredient $ingredient): void
    {
        Gate::authorize('access-inventory');

        $this->ingredient = $ingredient;
    }

    /**
     * Name the tab after the ingredient being worked on.
     */
    public function rendering(View $view): void
    {
        $view->title($this->ingredient->name);
    }

    /**
     * The ledger, newest first.
     *
     * @return Collection<int, InventoryMovement>
     */
    #[Computed]
    public function movements(): Collection
    {
        return $this->ingredient->movements()
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Current stock, formatted.
     */
    #[Computed]
    public function stock(): string
    {
        return $this->ingredient->stock();
    }

    /**
     * The kinds of movement that can be recorded by hand.
     *
     * @return array<int, InventoryMovementType>
     */
    public function types(): array
    {
        return InventoryMovementType::cases();
    }

    /**
     * The chosen type, which decides whether the direction and reason fields
     * are of any use.
     */
    #[Computed]
    public function chosenType(): InventoryMovementType
    {
        return InventoryMovementType::tryFrom($this->type) ?? InventoryMovementType::Received;
    }

    /**
     * Record a stock movement against this ingredient.
     */
    public function record(): void
    {
        Gate::authorize('access-inventory');

        $validated = $this->validate([
            'type' => ['required', Rule::enum(InventoryMovementType::class)],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'note' => [
                Rule::requiredIf(fn (): bool => $this->chosenType->requiresNote()),
                'nullable', 'string', 'max:255',
            ],
        ]);

        $type = InventoryMovementType::from($validated['type']);

        // The quantity is entered as a magnitude; the type decides the sign, and
        // only falls back to the chosen direction when it genuinely has both to
        // choose from. A receipt cannot be talked into removing stock, and a
        // bake cannot be talked into adding any, whatever the form submits.
        $direction = $type->fixedDirection()
            ?? ($validated['direction'] === 'out' ? -1 : 1);

        $thousandths = $direction * Ingredient::quantityToThousandths($validated['quantity']);

        try {
            app(RecordInventoryMovement::class)->handle(
                $this->ingredient,
                Auth::user(),
                $type,
                $thousandths,
                $validated['note'] ?: null,
            );
        } catch (\RuntimeException $exception) {
            $this->addError('quantity', $exception->getMessage());

            return;
        }

        $this->reset('quantity', 'note');
        $this->ingredient->refresh();

        unset($this->movements, $this->stock);

        Flux::toast(variant: 'success', text: __('Stock movement recorded.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $ingredient->name }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Stocked in :unit.', ['unit' => $ingredient->unit->label()]) }}
                @if ($ingredient->reorderLevelInThousandths() > 0)
                    {{ __('Low at :level :unit.', ['level' => $ingredient->reorderLevel(), 'unit' => $ingredient->unit->abbreviation()]) }}
                @endif
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:button size="sm" variant="ghost" :href="route('employee.inventory.edit', $ingredient)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
            <flux:button size="sm" variant="ghost" :href="route('employee.inventory.index')" wire:navigate>
                {{ __('Back to inventory') }}
            </flux:button>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6">
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('In stock') }}</span>
                <p class="mt-1 text-3xl font-semibold">
                    {{ $this->stock }}
                    <span class="text-lg font-normal text-zinc-500">{{ $ingredient->unit->abbreviation() }}</span>
                </p>

                <div class="mt-3">
                    @if (! $ingredient->is_active)
                        <flux:badge size="sm" color="zinc">{{ __('Not stocked') }}</flux:badge>
                    @elseif ($ingredient->isOutOfStock())
                        <flux:badge size="sm" color="red">{{ __('Out of stock') }}</flux:badge>
                    @elseif ($ingredient->isLowStock())
                        <flux:badge size="sm" color="amber">{{ __('Low') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="green">{{ __('In stock') }}</flux:badge>
                    @endif
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Record movement') }}</flux:heading>

                <form wire:submit="record" class="mt-4 flex flex-col gap-4">
                    <flux:select wire:model.live="type" :label="__('Reason')" required>
                        @foreach ($this->types() as $movementType)
                            <flux:select.option :value="$movementType->value">{{ $movementType->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->chosenType->fixedDirection() === null)
                        <flux:radio.group wire:model.live="direction" :label="__('Direction')" variant="segmented">
                            <flux:radio value="in" :label="__('Add')" />
                            <flux:radio value="out" :label="__('Remove')" />
                        </flux:radio.group>
                    @endif

                    <flux:input
                        wire:model="quantity"
                        :label="__('Quantity in :unit', ['unit' => $ingredient->unit->abbreviation()])"
                        type="number"
                        step="0.001"
                        min="0.001"
                        required
                    />

                    <flux:input
                        wire:model="note"
                        :label="$this->chosenType->requiresNote() ? __('Reason') : __('Note (optional)')"
                        :required="$this->chosenType->requiresNote()"
                    />

                    <flux:button variant="primary" type="submit">{{ __('Record') }}</flux:button>
                </form>
            </flux:card>
        </div>

        <div class="lg:col-span-2">
            <flux:heading size="lg">{{ __('Stock ledger') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every movement, kept as recorded. Corrections are made by recording an adjustment, never by editing history.') }}</flux:text>

            <div class="mt-4">
                @if ($this->movements->isEmpty())
                    <flux:callout icon="clock">
                        <flux:callout.heading>{{ __('Nothing recorded yet') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Record a receipt to give this ingredient its opening stock.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <div class="overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('When') }}</flux:table.column>
                                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                                <flux:table.column>{{ __('Change') }}</flux:table.column>
                                <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
                                <flux:table.column>{{ __('Note') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->movements as $movement)
                                    <flux:table.row :key="$movement->id">
                                        <flux:table.cell>{{ $movement->occurred_at->format('d M Y, g:ia') }}</flux:table.cell>
                                        <flux:table.cell>{{ $movement->type->label() }}</flux:table.cell>
                                        <flux:table.cell class="{{ $movement->isIncrease() ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $movement->signedQuantity() }} {{ $ingredient->unit->abbreviation() }}
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $movement->recordedBy->name }}</flux:table.cell>
                                        <flux:table.cell class="text-zinc-500">{{ $movement->note ?? '—' }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
