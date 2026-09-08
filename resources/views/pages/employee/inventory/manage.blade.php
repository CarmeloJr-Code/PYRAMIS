<?php

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage ingredient')] class extends Component {
    #[Locked]
    public ?int $ingredientId = null;

    /**
     * Whether the ledger already describes quantities in the current unit.
     */
    #[Locked]
    public bool $unitIsLocked = false;

    public string $name = '';

    public string $unit = '';

    public string $reorder_level = '0';

    public bool $is_active = true;

    /**
     * Mount the component for either a new or an existing ingredient.
     */
    public function mount(?Ingredient $ingredient = null): void
    {
        Gate::authorize('access-inventory');

        if ($ingredient?->exists) {
            $this->ingredientId = $ingredient->id;
            $this->name = $ingredient->name;
            $this->unit = $ingredient->unit->value;
            $this->reorder_level = $ingredient->reorderLevel();
            $this->is_active = $ingredient->is_active;

            // Every movement is a quantity in the unit that was set when it was
            // recorded. Changing the unit afterwards would silently reinterpret
            // the whole ledger — 5 kg becoming 5 g — so once there is history,
            // the unit is fixed.
            $this->unitIsLocked = $ingredient->movements()->exists();
        }
    }

    /**
     * The units an ingredient can be stocked in.
     *
     * @return array<int, IngredientUnit>
     */
    public function units(): array
    {
        return IngredientUnit::cases();
    }

    /**
     * Persist the ingredient.
     */
    public function save(): void
    {
        Gate::authorize('access-inventory');

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('ingredients', 'name')->ignore($this->ingredientId),
            ],
            'unit' => ['required', Rule::enum(IngredientUnit::class)],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
        ]);

        $ingredient = $this->ingredientId === null
            ? new Ingredient
            : Ingredient::findOrFail($this->ingredientId);

        // The form is client state, so a locked unit is re-checked here rather
        // than trusted to have stayed disabled in the browser.
        $unit = $ingredient->exists && $ingredient->movements()->exists()
            ? $ingredient->unit
            : IngredientUnit::from($validated['unit']);

        $ingredient->fill([
            'name' => $validated['name'],
            'unit' => $unit,
            'reorder_level' => $validated['reorder_level'],
            'is_active' => $validated['is_active'],
        ])->save();

        Flux::toast(variant: 'success', text: __('Ingredient saved.'));

        $this->redirectRoute('employee.inventory.show', $ingredient, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $ingredientId === null ? __('New ingredient') : __('Edit ingredient') }}
    </flux:heading>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" required autofocus />

        <flux:select wire:model="unit" :label="__('Stocked in')" required :disabled="$unitIsLocked">
            <flux:select.option value="">{{ __('Choose a unit') }}</flux:select.option>
            @foreach ($this->units() as $unit)
                <flux:select.option :value="$unit->value">{{ $unit->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($unitIsLocked)
            <flux:text class="-mt-4 text-sm">
                {{ __('The unit is fixed once stock has been recorded, so the existing movements keep their meaning.') }}
            </flux:text>
        @endif

        <flux:input
            wire:model="reorder_level"
            :label="__('Reorder level')"
            :description="__('Stock at or below this counts as low. Leave at 0 if the business has not set one.')"
            type="number"
            step="0.001"
            min="0"
        />

        <flux:switch
            wire:model="is_active"
            :label="__('Currently stocked')"
            :description="__('Ingredients no longer bought are kept, not deleted, so their history survives.')"
        />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save ingredient') }}</flux:button>
            <flux:button variant="ghost" :href="route('employee.inventory.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
