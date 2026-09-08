<?php

use App\Models\Ingredient;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ProductVariant $productVariant;

    #[Locked]
    public ?int $recipeId = null;

    public int $yield_quantity = 1;

    public string $notes = '';

    /**
     * The editable ingredient rows, per batch.
     *
     * @var array<int, array{id: int|null, ingredient_id: int|null, quantity: string}>
     */
    public array $items = [];

    /**
     * How many units the planner is costing out.
     */
    public int $plannedUnits = 1;

    /**
     * Mount the component for the size being written up.
     */
    public function mount(ProductVariant $productVariant): void
    {
        Gate::authorize('access-production');

        $this->productVariant = $productVariant->load('product');

        $recipe = $productVariant->recipe()->with('items')->first();

        if ($recipe !== null) {
            $this->recipeId = $recipe->id;
            $this->yield_quantity = $recipe->yield_quantity;
            $this->notes = $recipe->notes ?? '';
            $this->plannedUnits = $recipe->yield_quantity;

            $this->items = $recipe->items
                ->map(fn (RecipeItem $item): array => [
                    'id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
                    'quantity' => $item->amount(),
                ])
                ->all();
        }

        if ($this->items === []) {
            $this->addItem();
        }
    }

    /**
     * Name the tab after the size being written up.
     */
    public function rendering(View $view): void
    {
        $view->title(__('Recipe: :product :variant', [
            'product' => $this->productVariant->product->name,
            'variant' => $this->productVariant->name,
        ]));
    }

    /**
     * The ingredients a recipe can call for, with their stock.
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
     * What the saved recipe would take to make the planned number of units, and
     * whether the stockroom can cover it.
     *
     * Read from the persisted recipe rather than the form, so the plan always
     * describes something the bakery could actually run.
     *
     * @return Collection<int, array{ingredient: Ingredient, required: string, short: bool}>
     */
    #[Computed]
    public function plan(): Collection
    {
        $recipe = $this->recipeId === null
            ? null
            : Recipe::with('items')->find($this->recipeId);

        if ($recipe === null || $this->plannedUnits < 1) {
            return collect();
        }

        $ingredients = $this->ingredients->keyBy('id');

        return collect($recipe->requirementsInThousandths($this->plannedUnits))
            ->map(function (int $required, int $ingredientId) use ($ingredients): ?array {
                $ingredient = $ingredients->get($ingredientId);

                if ($ingredient === null) {
                    return null;
                }

                return [
                    'ingredient' => $ingredient,
                    'required' => Ingredient::formatQuantity($required),
                    'short' => $ingredient->stockInThousandths() < $required,
                ];
            })
            ->filter()
            ->sortBy(fn (array $row): string => $row['ingredient']->name)
            ->values();
    }

    /**
     * Append an empty ingredient row.
     */
    public function addItem(): void
    {
        $this->items[] = ['id' => null, 'ingredient_id' => null, 'quantity' => ''];
    }

    /**
     * Drop an ingredient row. A recipe must keep at least one.
     */
    public function removeItem(int $index): void
    {
        unset($this->items[$index]);

        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->addItem();
        }
    }

    /**
     * Persist the recipe and its lines.
     */
    public function save(): void
    {
        Gate::authorize('access-production');

        $validated = $this->validate([
            'yield_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
        ]);

        // The form is client state, so every ingredient is re-resolved and
        // anything no longer stocked is refused — a recipe must not quietly
        // start calling for something the stockroom has retired.
        $ingredientIds = array_column($validated['items'], 'ingredient_id');

        $ingredients = Ingredient::query()
            ->active()
            ->whereIn('id', $ingredientIds)
            ->pluck('id')
            ->all();

        if (count($ingredients) !== count($ingredientIds)) {
            $this->addError('items', __('Something on this recipe is no longer stocked. Please check the ingredients.'));

            return;
        }

        DB::transaction(function () use ($validated): void {
            $recipe = $this->recipeId === null
                ? new Recipe(['product_variant_id' => $this->productVariant->id])
                : Recipe::findOrFail($this->recipeId);

            $recipe->fill([
                'product_variant_id' => $this->productVariant->id,
                'yield_quantity' => $validated['yield_quantity'],
                'notes' => $validated['notes'] ?: null,
            ])->save();

            // Only ids this recipe already owns may be updated — a submitted id
            // belonging to another recipe is treated as a new row, never adopted.
            $ownedIds = $recipe->items()->pluck('id')->all();
            $keptIds = [];

            foreach ($validated['items'] as $row) {
                $attributes = [
                    'ingredient_id' => $row['ingredient_id'],
                    'quantity' => $row['quantity'],
                ];

                $item = in_array($row['id'] ?? null, $ownedIds, strict: true)
                    ? tap($recipe->items()->findOrFail($row['id']))->update($attributes)
                    : $recipe->items()->create($attributes);

                $keptIds[] = $item->id;
            }

            $recipe->items()->whereNotIn('id', $keptIds)->delete();

            $this->recipeId = $recipe->id;
        });

        unset($this->plan);

        Flux::toast(variant: 'success', text: __('Recipe saved.'));

        $this->redirectRoute('employee.production.recipes.index', navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $productVariant->product->name }}</flux:heading>
            <flux:text class="mt-2">{{ __('Recipe for the :variant size.', ['variant' => $productVariant->name]) }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.production.recipes.index')" wire:navigate>
            {{ __('Back to recipes') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->ingredients->isEmpty())
        <flux:callout icon="beaker">
            <flux:callout.heading>{{ __('No ingredients are stocked') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add ingredients under Inventory before writing up a recipe.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-8 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <form wire:submit="save" class="flex flex-col gap-6">
                    <flux:input
                        wire:model="yield_quantity"
                        :label="__('One batch makes')"
                        :description="__('How many of this size come out of a single batch. What a run of any other number takes is scaled from here.')"
                        type="number"
                        min="1"
                        required
                    />

                    <flux:separator variant="subtle" />

                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <flux:heading size="lg">{{ __('Per batch') }}</flux:heading>
                            <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addItem">
                                {{ __('Add ingredient') }}
                            </flux:button>
                        </div>

                        @error('items')
                            <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror

                        @foreach ($items as $index => $item)
                            <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="item-{{ $index }}">
                                <flux:select wire:model="items.{{ $index }}.ingredient_id" :label="__('Ingredient')" class="flex-1">
                                    <flux:select.option value="">{{ __('Choose an ingredient') }}</flux:select.option>
                                    @foreach ($this->ingredients as $ingredient)
                                        <flux:select.option :value="$ingredient->id">
                                            {{ $ingredient->name }} ({{ $ingredient->unit->abbreviation() }})
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input
                                    wire:model="items.{{ $index }}.quantity"
                                    :label="__('Quantity')"
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
                                        wire:click="removeItem({{ $index }})"
                                        :disabled="count($items) === 1"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <flux:textarea
                        wire:model="notes"
                        :label="__('Method notes (optional)')"
                        rows="4"
                        :placeholder="__('Anything the baker needs alongside the quantities.')"
                    />

                    <div class="flex items-center gap-3">
                        <flux:button variant="primary" type="submit">{{ __('Save recipe') }}</flux:button>
                        <flux:button variant="ghost" :href="route('employee.production.recipes.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="lg:col-span-2">
                <flux:heading size="lg">{{ __('Ingredients required') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Scaled from the saved recipe and checked against stock.') }}</flux:text>

                <flux:input
                    wire:model.live="plannedUnits"
                    :label="__('To make')"
                    type="number"
                    min="1"
                    class="mt-4 max-w-40"
                />

                @if ($this->plan->isEmpty())
                    <flux:callout icon="calculator" class="mt-4">
                        <flux:callout.heading>{{ __('Nothing to plan yet') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Save the recipe to see what a run of it would take.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Ingredient') }}</flux:table.column>
                                <flux:table.column>{{ __('Needed') }}</flux:table.column>
                                <flux:table.column>{{ __('In stock') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->plan as $row)
                                    <flux:table.row :key="$row['ingredient']->id">
                                        <flux:table.cell class="font-medium">{{ $row['ingredient']->name }}</flux:table.cell>
                                        <flux:table.cell>
                                            {{ $row['required'] }} {{ $row['ingredient']->unit->abbreviation() }}
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if ($row['short'])
                                                <flux:badge size="sm" color="red">
                                                    {{ $row['ingredient']->stock() }} {{ $row['ingredient']->unit->abbreviation() }}
                                                </flux:badge>
                                            @else
                                                {{ $row['ingredient']->stock() }} {{ $row['ingredient']->unit->abbreviation() }}
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
    @endif
</section>
