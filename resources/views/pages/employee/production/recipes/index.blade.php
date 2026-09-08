<?php

use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Recipes')] class extends Component {
    #[Url(as: 'missing', except: false)]
    public bool $onlyMissing = false;

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
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Recipes') }}</flux:heading>
            <flux:text class="mt-2">{{ __('What each size is made of, per batch. A size without a recipe cannot be produced against the stockroom.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.production')" wire:navigate>
            {{ __('Back to production') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:switch wire:model.live="onlyMissing" :label="__('Only sizes without a recipe')" />

        @if ($this->missingCount > 0)
            <flux:badge color="amber">
                {{ trans_choice('{1} :count size has no recipe|[2,*] :count sizes have no recipe', $this->missingCount, ['count' => $this->missingCount]) }}
            </flux:badge>
        @endif
    </div>

    @if ($this->variants->isEmpty())
        <flux:callout icon="book-open">
            <flux:callout.heading>{{ __('Nothing to show') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Every size has a recipe, or the catalogue is empty.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Batch makes') }}</flux:table.column>
                    <flux:table.column>{{ __('Ingredients') }}</flux:table.column>
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
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('employee.production.recipes.manage', $variant)" wire:navigate>
                                    {{ $variant->recipe ? __('Edit') : __('Add recipe') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
