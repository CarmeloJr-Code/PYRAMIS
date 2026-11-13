<?php

use App\Enums\RecipeStatus;
use App\Models\ProductVariant;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Recipes')] class extends Component {
    #[Url(as: 'missing', except: false)]
    public bool $onlyMissing = false;

    #[Url(as: 'pending', except: false)]
    public bool $onlyPending = false;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-production');
    }

    /**
     * Every sellable size, with the recipe it has or has not got yet.
     *
     * @return Collection<int, ProductVariant>
     */
    #[Computed]
    public function variants(): Collection
    {
        return ProductVariant::query()
            ->with(['product', 'recipe.items'])
            ->when($this->onlyMissing, fn ($query) => $query->whereDoesntHave('recipe'))
            ->when($this->onlyPending, fn ($query) => $query->whereHas('recipe', fn ($recipe) => $recipe->where('status', RecipeStatus::Pending)))
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->name)
            ->values();
    }

    /**
     * How many sizes are still without one.
     */
    #[Computed]
    public function missingCount(): int
    {
        return ProductVariant::query()->whereDoesntHave('recipe')->count();
    }

    /**
     * How many recipes are waiting for an Administrator (BR-016).
     */
    #[Computed]
    public function pendingCount(): int
    {
        return Recipe::query()->where('status', RecipeStatus::Pending)->count();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Recipes') }}</flux:heading>
            <flux:text class="mt-2">{{ __('What each size is made of, per batch. A size can\'t be baked until it has a recipe an administrator has approved.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.production')" wire:navigate>
            {{ __('Back to production') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-6">
            <flux:switch wire:model.live="onlyMissing" :label="__('Only sizes without a recipe')" />
            <flux:switch wire:model.live="onlyPending" :label="__('Only waiting for approval')" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->pendingCount > 0)
                <flux:badge color="amber">
                    {{ trans_choice('{1} :count recipe is waiting for approval|[2,*] :count recipes are waiting for approval', $this->pendingCount, ['count' => $this->pendingCount]) }}
                </flux:badge>
            @endif

            @if ($this->missingCount > 0)
                <flux:badge color="amber">
                    {{ trans_choice('{1} :count size has no recipe|[2,*] :count sizes have no recipe', $this->missingCount, ['count' => $this->missingCount]) }}
                </flux:badge>
            @endif
        </div>
    </div>

    @if ($this->variants->isEmpty())
        <flux:callout icon="book-open">
            <flux:callout.heading>{{ __('Nothing to show') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No size matches, or the catalogue is empty.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Batch makes') }}</flux:table.column>
                    <flux:table.column>{{ __('Ingredients') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->variants as $variant)
                        <flux:table.row :key="$variant->id">
                            <flux:table.cell class="font-medium">{{ $variant->product->name }}</flux:table.cell>
                            <flux:table.cell>{{ $variant->name }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($variant->recipe)
                                    {{ trans_choice('{1} :count unit|[2,*] :count units', $variant->recipe->yield_quantity, ['count' => $variant->recipe->yield_quantity]) }}
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('No recipe') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $variant->recipe?->items->count() ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($variant->recipe)
                                    <flux:badge size="sm" :color="$variant->recipe->status->color()">{{ __($variant->recipe->status->label()) }}</flux:badge>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('employee.production.recipes.manage', $variant)" wire:navigate>
                                    @if ($variant->recipe === null)
                                        {{ __('Add recipe') }}
                                    @elseif ($variant->recipe->status === RecipeStatus::Pending && auth()->user()->can('approve-recipes'))
                                        {{ __('Review') }}
                                    @else
                                        {{ __('Edit') }}
                                    @endif
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
