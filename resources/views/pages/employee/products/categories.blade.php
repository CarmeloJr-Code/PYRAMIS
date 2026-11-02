<?php

use App\Models\Category;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Product categories')] class extends Component {
    public string $name = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-products');
    }

    /**
     * The categories, with how many products are filed under each.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();
    }

    /**
     * Add a category for products to be filed under.
     *
     * It reaches the storefront only once an active product is filed under
     * it: the menu lists only categories with something to sell.
     */
    public function add(): void
    {
        Gate::authorize('manage-products');

        // Livewire skips the TrimStrings middleware, so a stray space would
        // otherwise be stored, and slip a duplicate name past unique.
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255', Rule::unique('categories', 'name')],
        ]);

        Category::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
        ]);

        $this->reset('name');

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category added.'));
    }

    /**
     * Build a slug no other category is already using.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Product categories') }}</flux:heading>
            <flux:text class="mt-2">{{ __('The groups products are sorted into on the menu. A group shows on the storefront once it has a product.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.products.index')" wire:navigate>
            {{ __('Back to products') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="overflow-x-auto lg:col-span-2">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Products') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->categories as $category)
                        <flux:table.row :key="$category->id">
                            <flux:table.cell class="font-medium">{{ $category->name }}</flux:table.cell>
                            <flux:table.cell>{{ $category->products_count }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div>
            <flux:card>
                <flux:heading size="lg">{{ __('Add a category') }}</flux:heading>

                <form wire:submit="add" class="mt-4 flex flex-col gap-4">
                    <flux:input wire:model="name" :label="__('Name')" maxlength="255" required />
                    <flux:button variant="primary" type="submit">{{ __('Add') }}</flux:button>
                </form>
            </flux:card>
        </div>
    </div>
</section>
