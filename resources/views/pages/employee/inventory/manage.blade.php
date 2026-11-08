<?php

use App\Enums\IngredientUnit;
use App\Enums\RecipeMeasure;
use App\Models\Ingredient;
use App\Models\RecipeItem;
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

    /**
     * How much one pack, can or piece holds, in the measure below.
     */
    public string $content_per_unit = '';

    public string $content_measure = 'gram';

    /**
     * What one level cup weighs, in grams or milliliters.
     */
    public string $cup_weight = '';

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
            $this->content_per_unit = $ingredient->content_per_unit === null
                ? ''
                : Ingredient::quantityFromThousandths(Ingredient::quantityToThousandths($ingredient->content_per_unit));
            $this->content_measure = $ingredient->content_measure?->value ?? IngredientUnit::Gram->value;
            $this->cup_weight = $ingredient->cup_weight === null
                ? ''
                : Ingredient::quantityFromThousandths(Ingredient::quantityToThousandths($ingredient->cup_weight));
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
     * The unit as currently chosen on the form, if it is one.
     */
    public function chosenUnit(): ?IngredientUnit
    {
        return IngredientUnit::tryFrom($this->unit);
    }

    /**
     * The grams or milliliters a cup weight is entered in, as the form stands.
     */
    public function chosenBaseMeasure(): ?IngredientUnit
    {
        $unit = $this->chosenUnit();

        if ($unit === null) {
            return null;
        }

        return $unit->baseMeasure()
            ?? (trim($this->content_per_unit) === '' ? null : IngredientUnit::tryFrom($this->content_measure));
    }

    /**
     * Persist the ingredient.
     */
    public function save(): void
    {
        Gate::authorize('access-inventory');

        // Livewire skips the TrimStrings middleware, so a stray space would
        // otherwise be stored, and slip a duplicate name past unique.
        $this->name = trim($this->name);
        $this->content_per_unit = trim($this->content_per_unit);
        $this->cup_weight = trim($this->cup_weight);

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('ingredients', 'name')->ignore($this->ingredientId),
            ],
            'unit' => ['required', Rule::enum(IngredientUnit::class)],
            'reorder_level' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999'],
            'content_per_unit' => ['nullable', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
            'content_measure' => ['required', Rule::in([IngredientUnit::Gram->value, IngredientUnit::Milliliter->value])],
            'cup_weight' => ['nullable', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
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

        // What one unit holds only means anything for a pack, can or piece; a
        // kilogram already says it is a thousand grams.
        $holdsContent = $unit->needsContent() && filled($validated['content_per_unit']);

        $baseBefore = $ingredient->exists ? $ingredient->baseMeasure() : null;

        $ingredient->fill([
            'name' => $validated['name'],
            'unit' => $unit,
            'content_per_unit' => $holdsContent ? $validated['content_per_unit'] : null,
            'content_measure' => $holdsContent ? $validated['content_measure'] : null,
            // A cup weight is in grams or milliliters, so it means nothing until
            // the ingredient has one.
            'cup_weight' => filled($validated['cup_weight']) && ($unit->baseMeasure() !== null || $holdsContent)
                ? $validated['cup_weight']
                : null,
            'reorder_level' => $validated['reorder_level'],
            'is_active' => $validated['is_active'],
        ]);

        if ($ingredient->exists && ! $this->recipesStillConvert($ingredient, $baseBefore)) {
            return;
        }

        $ingredient->save();

        Flux::toast(variant: 'success', text: __('Ingredient saved.'));

        $this->redirectRoute('employee.inventory.show', $ingredient, navigate: true);
    }

    /**
     * Refuse a change that would leave a recipe line unable to convert.
     *
     * A recipe written in cups needs the cup weight, one in grams needs to
     * know what a pack holds, and switching a pack from grams to milliliters
     * would quietly change what every such line means.
     */
    private function recipesStillConvert(Ingredient $ingredient, ?IngredientUnit $baseBefore): bool
    {
        $lines = RecipeItem::query()
            ->with('recipe.productVariant.product')
            ->where('ingredient_id', $ingredient->id)
            ->where('measure', '!=', RecipeMeasure::Unit->value)
            ->get();

        $broken = $lines->first(fn (RecipeItem $line): bool => ! $ingredient->allowsMeasure($line->measure)
            || $ingredient->baseMeasure() !== $baseBefore);

        if ($broken === null) {
            return true;
        }

        $variant = $broken->recipe->productVariant;

        $this->addError('conversions', __('The :product :variant recipe calls for this in :measure. Change that recipe first.', [
            'product' => $variant->product->name,
            'variant' => $variant->name,
            'measure' => $broken->measure->abbreviation($ingredient),
        ]));

        return false;
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $ingredientId === null ? __('New ingredient') : __('Edit ingredient') }}
    </flux:heading>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" maxlength="255" required autofocus />

        <flux:select wire:model.live="unit" :label="__('Stocked in')" required :disabled="$unitIsLocked">
            <flux:select.option value="">{{ __('Choose a unit') }}</flux:select.option>
            @foreach ($this->units() as $unit)
                <flux:select.option :value="$unit->value">{{ $unit->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($unitIsLocked)
            <flux:text class="-mt-4 text-sm">
                {{ __('The unit can\'t be changed once stock has been recorded.') }}
            </flux:text>
        @endif

        @php($chosenUnit = $this->chosenUnit())

        @if ($chosenUnit?->needsContent())
            <div class="flex flex-col gap-2">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <flux:input
                        wire:model.live.blur="content_per_unit"
                        :label="__('Each one holds')"
                        type="number"
                        step="0.001"
                        min="0.001"
                        max="999999999"
                        inputmode="decimal"
                        x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
                        class="flex-1"
                    />

                    <flux:select wire:model.live="content_measure" :label="__('Measured in')" class="sm:w-48">
                        <flux:select.option value="gram">{{ __('Grams') }}</flux:select.option>
                        <flux:select.option value="milliliter">{{ __('Milliliters') }}</flux:select.option>
                    </flux:select>
                </div>

                <flux:text class="text-sm">
                    {{ __('Lets a recipe call for this in grams, milliliters, cups or spoons. Leave blank if recipes only count whole :units.', ['units' => $chosenUnit->abbreviation()]) }}
                </flux:text>
            </div>
        @elseif ($chosenUnit !== null)
            <flux:text class="-mt-2 text-sm">
                {{ __('1 :unit = :factor :base, so a recipe can call for this in :base, cups or spoons.', [
                    'unit' => $chosenUnit->abbreviation(),
                    'factor' => $chosenUnit->baseFactor(),
                    'base' => $chosenUnit->baseMeasure()->abbreviation(),
                ]) }}
            </flux:text>
        @endif

        @php($base = $this->chosenBaseMeasure())

        @if ($base !== null)
            <flux:input
                wire:model="cup_weight"
                :label="__('1 cup weighs (:base)', ['base' => $base->abbreviation()])"
                :description="__('Optional. 1 tbsp is taken as a sixteenth of a cup and 1 tsp as a forty-eighth.')"
                type="number"
                step="0.001"
                min="0.001"
                max="999999999"
                inputmode="decimal"
                x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
            />
        @endif

        @error('conversions')
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
        @enderror

        <flux:input
            wire:model="reorder_level"
            :label="__('Reorder level')"
            :description="__('Stock at or below this counts as low. Leave at 0 if there isn\'t one.')"
            type="number"
            step="0.001"
            min="0"
            max="999999999"
            inputmode="decimal"
            x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
            x-on:input="if ($el.value > 999999999) { $el.value = 999999999; $el.dispatchEvent(new Event('input')) }"
        />

        <flux:switch
            wire:model="is_active"
            :label="__('Currently stocked')"
            :description="__('Ingredients you no longer buy are kept, not deleted, so their history stays.')"
        />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save ingredient') }}</flux:button>
            <flux:button variant="ghost" :href="route('employee.inventory.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
