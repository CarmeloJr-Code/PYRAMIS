<?php

use App\Models\Recipe;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production')] class extends Component {
    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-production');
    }

    /**
     * How many sizes the business has written a recipe for.
     */
    #[Computed]
    public function recipeCount(): int
    {
        return Recipe::query()->count();
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Production') }}</flux:heading>
    <flux:text class="mt-2">{{ __('What the bakery makes, what it takes, and what that draws from the stockroom.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-4 md:grid-cols-3">
        <flux:card>
            <flux:heading size="lg">{{ __('Recipes') }}</flux:heading>
            <flux:text class="mt-2">
                {{ trans_choice(
                    '{0} No size has a recipe yet.|{1} One size has a recipe.|[2,*] :count sizes have a recipe.',
                    $this->recipeCount,
                    ['count' => $this->recipeCount],
                ) }}
            </flux:text>
            <flux:button :href="route('employee.production.recipes.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                {{ __('Open') }}
            </flux:button>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Inventory') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Ingredient stock, receipts and what each bake consumed.') }}</flux:text>
            <flux:button :href="route('employee.inventory.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                {{ __('Open') }}
            </flux:button>
        </flux:card>
    </div>

    <flux:callout icon="wrench-screwdriver" class="mt-6">
        <flux:callout.heading>{{ __('Production logging is next') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Recipes are in place, so a baking run can be logged against one — consuming its ingredients and adding the finished product in a single step. Until then, record what a bake used under Inventory.') }}
        </flux:callout.text>
    </flux:callout>
</section>
