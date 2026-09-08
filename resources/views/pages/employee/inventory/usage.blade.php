<?php

use App\Actions\RecordInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Ingredient usage')] class extends Component {
    #[Url(as: 'date', except: '')]
    public string $date = '';

    /**
     * What is being taken from the stockroom.
     *
     * @var array<int, array{ingredient_id: int|null, quantity: string}>
     */
    public array $lines = [];

    /**
     * What it was used for, kept against every line of the batch.
     */
    public string $note = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-inventory');

        if ($this->date === '') {
            $this->date = now()->toDateString();
        }

        $this->addLine();
    }

    /**
     * The ingredients that can be drawn on, with their stock.
     *
     * @return Collection<int, Ingredient>
     */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()
            ->active()
            ->withStock()
            ->orderBy('name')
            ->get();
    }

    /**
     * What was consumed on the chosen day, newest first.
     *
     * @return Collection<int, InventoryMovement>
     */
    #[Computed]
    public function movements(): Collection
    {
        return InventoryMovement::query()
            ->where('type', InventoryMovementType::Usage)
            ->whereDate('occurred_at', $this->date)
            ->with(['ingredient', 'recordedBy', 'productionRun'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The day's consumption per ingredient — the figure a forecast will
     * eventually be built on, so it is derived from the ledger rather than
     * accumulated anywhere.
     *
     * @return Collection<int, array{ingredient: Ingredient, used: string}>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->movements
            ->groupBy('ingredient_id')
            ->map(fn (Collection $movements): array => [
                'ingredient' => $movements->first()->ingredient,
                'used' => Ingredient::formatQuantity(abs(
                    $movements->sum(fn (InventoryMovement $movement): int => $movement->quantityInThousandths()),
                )),
            ])
            ->sortBy(fn (array $row): string => $row['ingredient']->name)
            ->values();
    }

    /**
     * Append an empty line.
     */
    public function addLine(): void
    {
        $this->lines[] = ['ingredient_id' => null, 'quantity' => ''];
    }

    /**
     * Drop a line. A batch must keep at least one.
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
     * Record everything the batch consumed.
     */
    public function record(): void
    {
        Gate::authorize('access-inventory');

        $validated = $this->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // The form is client state, so every ingredient is re-resolved from the
        // database and anything no longer stocked is refused — the same rule the
        // counter sale follows.
        $ingredientIds = array_column($validated['lines'], 'ingredient_id');

        $ingredients = Ingredient::query()
            ->active()
            ->whereIn('id', $ingredientIds)
            ->get()
            ->keyBy('id');

        if ($ingredients->count() !== count($ingredientIds)) {
            $this->addError('lines', __('Something on this list is no longer stocked. Please check the items.'));

            return;
        }

        $action = app(RecordInventoryMovement::class);
        $employee = Auth::user();
        $note = $validated['note'] ?: null;

        try {
            // One transaction around the batch: a bake that runs short on its
            // last ingredient did not half-happen, so nothing is deducted.
            DB::transaction(function () use ($validated, $ingredients, $action, $employee, $note): void {
                foreach ($validated['lines'] as $line) {
                    $action->handle(
                        $ingredients[$line['ingredient_id']],
                        $employee,
                        InventoryMovementType::Usage,
                        -Ingredient::quantityToThousandths($line['quantity']),
                        $note,
                    );
                }
            });
        } catch (\RuntimeException $exception) {
            $this->addError('lines', $exception->getMessage());

            return;
        }

        $this->reset('lines', 'note');
        $this->addLine();

        unset($this->ingredients, $this->movements, $this->totals);

        Flux::toast(variant: 'success', text: __('Ingredient usage recorded.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Ingredient usage') }}</flux:heading>
            <flux:text class="mt-2">{{ __('What the bakery took from the stockroom, and what it was for.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.inventory.index')" wire:navigate>
            {{ __('Back to inventory') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <flux:heading size="lg">{{ __('Record usage') }}</flux:heading>

            @if ($this->ingredients->isEmpty())
                <flux:callout icon="beaker" class="mt-4">
                    <flux:callout.heading>{{ __('Nothing is stocked') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('Add an ingredient before recording what was used.') }}</flux:callout.text>
                </flux:callout>
            @else
                <form wire:submit="record" class="mt-4 flex flex-col gap-4">
                    @error('lines')
                        <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror

                    @foreach ($lines as $index => $line)
                        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="line-{{ $index }}">
                            <flux:select wire:model.live="lines.{{ $index }}.ingredient_id" :label="__('Ingredient')" class="flex-1">
                                <flux:select.option value="">{{ __('Choose an ingredient') }}</flux:select.option>
                                @foreach ($this->ingredients as $ingredient)
                                    <flux:select.option :value="$ingredient->id">
                                        {{ $ingredient->name }} &mdash; {{ $ingredient->stock() }} {{ $ingredient->unit->abbreviation() }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input
                                wire:model="lines.{{ $index }}.quantity"
                                :label="__('Used')"
                                type="number"
                                step="0.001"
                                min="0.001"
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

                    <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addLine" class="self-start">
                        {{ __('Add ingredient') }}
                    </flux:button>

                    <flux:input
                        wire:model="note"
                        :label="__('What it was for (optional)')"
                        placeholder="{{ __('Ube custard cake, 7 pans') }}"
                    />

                    <flux:button variant="primary" type="submit">{{ __('Record usage') }}</flux:button>
                </form>
            @endif
        </div>

        <div class="lg:col-span-3">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <flux:heading size="lg">{{ __('Used on this day') }}</flux:heading>

                <flux:input wire:model.live="date" type="date" :label="__('Day')" class="max-w-48" />
            </div>

            @if ($this->movements->isEmpty())
                <flux:callout icon="clock" class="mt-4">
                    <flux:callout.heading>{{ __('Nothing used') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('No ingredients were drawn from the stockroom on this day.') }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->totals as $total)
                        <flux:badge color="zinc" wire:key="total-{{ $total['ingredient']->id }}">
                            {{ $total['ingredient']->name }}: {{ $total['used'] }} {{ $total['ingredient']->unit->abbreviation() }}
                        </flux:badge>
                    @endforeach
                </div>

                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Time') }}</flux:table.column>
                            <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                            <flux:table.column>{{ __('Used') }}</flux:table.column>
                            <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
                            <flux:table.column>{{ __('For') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->movements as $movement)
                                <flux:table.row :key="$movement->id">
                                    <flux:table.cell>{{ $movement->occurred_at->format('g:ia') }}</flux:table.cell>
                                    <flux:table.cell class="font-medium">{{ $movement->ingredient->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $movement->magnitude() }} {{ $movement->ingredient->unit->abbreviation() }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $movement->recordedBy->name }}</flux:table.cell>
                                    <flux:table.cell class="text-zinc-500">
                                        @if ($movement->productionRun)
                                            <a href="{{ route('employee.production.runs.show', $movement->productionRun) }}" class="font-mono underline" wire:navigate>
                                                {{ $movement->productionRun->reference }}
                                            </a>
                                        @else
                                            {{ $movement->note ?? '—' }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>
    </div>
</section>
