<?php

use App\Enums\RecipeMeasure;
use App\Enums\RecipeStatus;
use App\Models\Ingredient;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ProductVariant $productVariant;

    #[Locked]
    public ?int $recipeId = null;

    /**
     * When the version on screen was saved. A decision is only taken on the
     * version the Administrator actually read, so one saved again since is
     * refused rather than approved unseen.
     */
    #[Locked]
    public ?int $submittedAt = null;

    /**
     * Why the recipe is being sent back.
     */
    public string $review_note = '';

    /**
     * A string, as every number a person types is: a strict int cannot hold
     * the empty string a cleared field sends, and Livewire drops the property
     * rather than refuse the request.
     */
    public string $yield_quantity = '1';

    public string $notes = '';

    /**
     * The editable ingredient rows, per batch.
     *
     * @var array<int, array{id: int|null, ingredient_id: int|null, quantity: string, measure: string}>
     */
    public array $items = [];

    /**
     * How many units the planner is costing out, as typed.
     */
    public string $plannedUnits = '1';

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
            $this->submittedAt = $recipe->submitted_at?->getTimestamp();
            $this->yield_quantity = (string) $recipe->yield_quantity;
            $this->notes = $recipe->notes ?? '';
            $this->plannedUnits = (string) $recipe->yield_quantity;

            $this->items = $recipe->items
                ->map(fn (RecipeItem $item): array => [
                    'id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
                    'quantity' => $item->amount(),
                    'measure' => $item->measure->value,
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
     * The saved recipe, with who wrote it and who decided on it.
     */
    #[Computed]
    public function recipe(): ?Recipe
    {
        return $this->recipeId === null
            ? null
            : Recipe::with(['submittedBy', 'reviewedBy'])->find($this->recipeId);
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
            : Recipe::with('items.ingredient')->find($this->recipeId);

        // A cleared or half-typed field plans nothing. Past what one run may
        // log, the plan would describe nothing the bakery could record, and
        // the thousandths arithmetic could overflow.
        $units = filter_var($this->plannedUnits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);

        if ($recipe === null || $units === false) {
            return collect();
        }

        $ingredients = $this->ingredients->keyBy('id');

        try {
            $requirements = $recipe->requirementsInThousandths($units);
        } catch (\RuntimeException) {
            // A conversion the saved recipe relies on has gone missing; saving
            // the form again names the line.
            return collect();
        }

        return collect($requirements)
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
     * The measures a row may be written in, for the ingredient it calls for.
     *
     * @return array<int, RecipeMeasure>
     */
    public function measuresFor(mixed $ingredientId): array
    {
        $ingredient = $this->ingredients->firstWhere('id', (int) $ingredientId);

        return $ingredient?->measuresAvailable() ?? [RecipeMeasure::Unit];
    }

    /**
     * What a row as entered would draw from stock, formatted with its unit, or
     * null when it is already written in the stocked unit or is not complete.
     *
     * @param  array{ingredient_id: int|string|null, quantity: string, measure: string}  $row
     */
    public function drawFor(array $row): ?string
    {
        $ingredient = $this->ingredients->firstWhere('id', (int) $row['ingredient_id']);
        $measure = RecipeMeasure::tryFrom($row['measure'] ?? '');

        if ($ingredient === null || $measure === null || $measure === RecipeMeasure::Unit
            || ! is_numeric($row['quantity']) || (float) $row['quantity'] <= 0 || (float) $row['quantity'] > 999999999
            || ! $ingredient->allowsMeasure($measure)) {
            return null;
        }

        $thousandths = (int) round($ingredient->toStockThousandths(
            Ingredient::quantityToThousandths($row['quantity']),
            $measure,
        ));

        return Ingredient::formatQuantity($thousandths).' '.$ingredient->unit->abbreviation();
    }

    /**
     * Keep a row's measure one its ingredient can be written in.
     */
    public function updatedItems(mixed $value, ?string $key = null): void
    {
        // Only a change of ingredient can strand a measure; a whole new set of
        // rows (no key) is left to save() to judge.
        [$index, $field] = array_pad(explode('.', (string) $key, 2), 2, null);

        if ($field !== 'ingredient_id' || ! isset($this->items[(int) $index])) {
            return;
        }

        $measure = RecipeMeasure::tryFrom($this->items[(int) $index]['measure'] ?? '');

        if ($measure === null || ! in_array($measure, $this->measuresFor($value), strict: true)) {
            $this->items[(int) $index]['measure'] = RecipeMeasure::Unit->value;
        }
    }

    /**
     * Append an empty ingredient row.
     */
    public function addItem(): void
    {
        $this->items[] = ['id' => null, 'ingredient_id' => null, 'quantity' => '', 'measure' => RecipeMeasure::Unit->value];
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

        // Livewire skips the TrimStrings middleware, so trim here.
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'yield_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
            'items.*.measure' => ['required', Rule::enum(RecipeMeasure::class)],
        ]);

        // The form is client state, so every ingredient is re-resolved and
        // anything no longer stocked is refused — a recipe must not quietly
        // start calling for something the stockroom has retired.
        $ingredientIds = array_column($validated['items'], 'ingredient_id');

        $ingredients = Ingredient::query()
            ->active()
            ->whereIn('id', $ingredientIds)
            ->get()
            ->keyBy('id');

        if ($ingredients->count() !== count($ingredientIds)) {
            $this->addError('items', __('Something on this recipe is no longer stocked. Please check the ingredients.'));

            return;
        }

        // The measure list on the form is a convenience; whether this
        // ingredient can actually be converted from that measure is decided
        // here, from what the stockroom has recorded about it.
        foreach ($validated['items'] as $index => $row) {
            $ingredient = $ingredients->get($row['ingredient_id']);
            $measure = RecipeMeasure::from($row['measure']);

            if (! $ingredient->allowsMeasure($measure)) {
                $this->addError("items.{$index}.measure", __(':ingredient can\'t be measured in :measure yet. Set its conversion under Inventory first.', [
                    'ingredient' => $ingredient->name,
                    'measure' => $measure->abbreviation($ingredient),
                ]));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function () use ($validated): void {
            $recipe = $this->recipeId === null
                ? new Recipe(['product_variant_id' => $this->productVariant->id])
                : Recipe::findOrFail($this->recipeId);

            $recipe->fill([
                'product_variant_id' => $this->productVariant->id,
                'yield_quantity' => (int) $validated['yield_quantity'],
                'notes' => $validated['notes'] ?: null,
            ]);

            // BR-016: an Administrator's save is approved as it stands; anyone
            // else's waits for one, including an edit to a recipe already in use.
            $recipe->markSubmittedBy(Auth::user());
            $recipe->save();

            // Only ids this recipe already owns may be updated — a submitted id
            // belonging to another recipe is treated as a new row, never adopted.
            $ownedIds = $recipe->items()->pluck('id')->all();
            $keptIds = [];

            foreach ($validated['items'] as $row) {
                $attributes = [
                    'ingredient_id' => $row['ingredient_id'],
                    'quantity' => $row['quantity'],
                    'measure' => $row['measure'],
                ];

                $item = in_array($row['id'] ?? null, $ownedIds, strict: true)
                    ? tap($recipe->items()->findOrFail($row['id']))->update($attributes)
                    : $recipe->items()->create($attributes);

                $keptIds[] = $item->id;
            }

            $recipe->items()->whereNotIn('id', $keptIds)->delete();

            $this->recipeId = $recipe->id;
        });

        unset($this->plan, $this->recipe);

        Flux::toast(variant: 'success', text: Auth::user()->can('approve-recipes')
            ? __('Recipe saved.')
            : __('Recipe sent for approval.'));

        $this->redirectRoute('employee.production.recipes.index', navigate: true);
    }

    /**
     * Approve the recipe as it was read.
     */
    public function approve(): void
    {
        Gate::authorize('approve-recipes');

        if ($this->decide(fn (Recipe $recipe) => $recipe->approve(Auth::user()))) {
            Flux::toast(variant: 'success', text: __('Recipe approved.'));

            $this->redirectRoute('employee.production.recipes.index', navigate: true);
        }
    }

    /**
     * Send the recipe back to be corrected, saying why.
     */
    public function sendBack(): void
    {
        Gate::authorize('approve-recipes');

        // Livewire skips the TrimStrings middleware, so trim here.
        $this->review_note = trim($this->review_note);

        $validated = $this->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        if ($this->decide(fn (Recipe $recipe) => $recipe->sendBack(Auth::user(), $validated['review_note']))) {
            Flux::toast(variant: 'success', text: __('Recipe sent back.'));

            $this->redirectRoute('employee.production.recipes.index', navigate: true);
        }
    }

    /**
     * Take a decision on the saved recipe, if it is still the one on screen.
     *
     * The row is locked so a save landing at the same moment queues behind
     * the decision or ahead of it, never in the middle.
     *
     * @param  Closure(Recipe): void  $decision
     */
    private function decide(Closure $decision): bool
    {
        $problem = DB::transaction(function () use ($decision): ?string {
            $recipe = Recipe::query()->lockForUpdate()->find($this->recipeId);

            if ($recipe === null || $recipe->status !== RecipeStatus::Pending) {
                return __('This recipe is not waiting for approval.');
            }

            if ($recipe->submitted_at?->getTimestamp() !== $this->submittedAt) {
                return __('This recipe was changed after you opened it. Reload the page to review it as it is now.');
            }

            $decision($recipe);

            return null;
        });

        if ($problem !== null) {
            $this->addError('review', $problem);
        }

        return $problem === null;
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

    @if ($this->recipe?->status === RecipeStatus::Pending)
        <flux:callout icon="clock" variant="warning" class="mb-6" data-test="recipe-review">
            <flux:callout.heading>{{ __('Waiting for approval') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Saved by :name on :date. This size can\'t be baked until an administrator approves the recipe.', [
                    'name' => $this->recipe->submittedBy?->name ?? __('someone'),
                    'date' => $this->recipe->submitted_at?->format('M j, Y g:ia'),
                ]) }}
            </flux:callout.text>

            @can('approve-recipes')
                <div class="mt-4 flex flex-col gap-4">
                    @error('review')
                        <flux:text class="text-danger">{{ $message }}</flux:text>
                    @enderror

                    <div>
                        <flux:button variant="primary" size="sm" type="button" wire:click="approve">{{ __('Approve recipe') }}</flux:button>
                    </div>

                    <form wire:submit="sendBack" class="flex flex-col gap-3">
                        <flux:textarea
                            wire:model="review_note"
                            :label="__('Or send it back')"
                            :placeholder="__('What needs changing before this can be approved.')"
                            rows="2"
                            maxlength="1000"
                        />

                        <div>
                            <flux:button size="sm" variant="danger" type="submit">{{ __('Send back') }}</flux:button>
                        </div>
                    </form>
                </div>
            @endcan
        </flux:callout>
    @elseif ($this->recipe?->status === RecipeStatus::Rejected)
        <flux:callout icon="arrow-uturn-left" variant="danger" class="mb-6" data-test="recipe-review">
            <flux:callout.heading>
                {{ __('Sent back by :name', ['name' => $this->recipe->reviewedBy?->name ?? __('an administrator')]) }}
            </flux:callout.heading>
            <flux:callout.text>{{ $this->recipe->review_note }}</flux:callout.text>
            <flux:callout.text>{{ __('Correct the recipe and save it to send it for approval again.') }}</flux:callout.text>
        </flux:callout>
    @endif

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
                        :description="__('How many of this size one batch makes. Other amounts are worked out from this.')"
                        type="number"
                        min="1"
                        step="1"
                        max="100000"
                        inputmode="numeric"
                        x-on:keydown="['e', 'E', '+', '-', '.', ','].includes($event.key) && $event.preventDefault()"
                        x-on:input="if ($el.value > 100000) { $el.value = 100000; $el.dispatchEvent(new Event('input')) }"
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
                            <flux:text class="text-danger">{{ $message }}</flux:text>
                        @enderror

                        @foreach ($items as $index => $item)
                            <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="item-{{ $index }}">
                                <flux:select wire:model.live="items.{{ $index }}.ingredient_id" :label="__('Ingredient')" class="flex-1">
                                    <flux:select.option value="">{{ __('Choose an ingredient') }}</flux:select.option>
                                    @foreach ($this->ingredients as $ingredient)
                                        <flux:select.option :value="$ingredient->id">
                                            {{ $ingredient->name }} ({{ $ingredient->unit->abbreviation() }})
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input
                                    wire:model.live.blur="items.{{ $index }}.quantity"
                                    :label="__('Quantity')"
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    max="999999999"
                                    inputmode="decimal"
                                    x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
                                    x-on:input="if ($el.value > 999999999) { $el.value = 999999999; $el.dispatchEvent(new Event('input')) }"
                                    class="md:w-32"
                                />

                                <div class="flex flex-col gap-1 md:w-28">
                                    @php($chosen = $this->ingredients->firstWhere('id', (int) $item['ingredient_id']))

                                    <flux:select wire:model.live="items.{{ $index }}.measure" :label="__('Measure')">
                                        @foreach ($this->measuresFor($item['ingredient_id']) as $measureOption)
                                            <flux:select.option :value="$measureOption->value">
                                                {{ $chosen === null ? __('unit') : $measureOption->abbreviation($chosen) }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if ($draw = $this->drawFor($item))
                                        <flux:text class="text-xs">{{ __('→ :draw', ['draw' => $draw]) }}</flux:text>
                                    @endif
                                </div>

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
                        maxlength="2000"
                        :placeholder="__('Anything the baker needs alongside the quantities.')"
                    />

                    @cannot('approve-recipes')
                        <flux:text>
                            {{ $this->recipe?->isApproved()
                                ? __('Saving a change sends this recipe back for approval. This size can\'t be baked until an administrator approves it again.')
                                : __('An administrator approves a recipe before this size can be baked.') }}
                        </flux:text>
                    @endcannot

                    <div class="flex items-center gap-3">
                        <flux:button variant="primary" type="submit">
                            @can('approve-recipes')
                                {{ $this->recipe === null || $this->recipe->isApproved() ? __('Save recipe') : __('Save and approve') }}
                            @else
                                {{ __('Send for approval') }}
                            @endcan
                        </flux:button>
                        <flux:button variant="ghost" :href="route('employee.production.recipes.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="lg:col-span-2">
                <flux:heading size="lg">{{ __('Ingredients required') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Scaled from the saved recipe, converted to each stocked unit, and checked against stock. Amounts round to the nearest thousandth of a unit.') }}</flux:text>

                <flux:input
                    wire:model.live="plannedUnits"
                    :label="__('To make')"
                    type="number"
                    min="1"
                    step="1"
                    max="100000"
                    inputmode="numeric"
                    x-on:keydown="['e', 'E', '+', '-', '.', ','].includes($event.key) && $event.preventDefault()"
                    x-on:input="if ($el.value > 100000) { $el.value = 100000; $el.dispatchEvent(new Event('input')) }"
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
