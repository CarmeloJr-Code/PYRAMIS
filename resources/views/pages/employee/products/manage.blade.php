<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage product')] class extends Component {
    #[Locked]
    public ?int $productId = null;

    public string $name = '';

    public ?int $category_id = null;

    public string $description = '';

    public bool $is_active = true;

    /**
     * The editable variant rows.
     *
     * @var array<int, array{id: int|null, name: string, price: string, is_available: bool}>
     */
    public array $variants = [];

    /**
     * Mount the component for either a new or an existing product.
     */
    public function mount(?Product $product = null): void
    {
        Gate::authorize('manage-products');

        if ($product?->exists) {
            $product->load('variants');

            $this->productId = $product->id;
            $this->name = $product->name;
            $this->category_id = $product->category_id;
            $this->description = $product->description ?? '';
            $this->is_active = $product->is_active;

            $this->variants = $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => (string) $variant->price,
                    'is_available' => $variant->is_available,
                ])
                ->all();
        }

        if ($this->variants === []) {
            $this->addVariant();
        }
    }

    /**
     * The categories a product can be filed under.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get();
    }

    /**
     * Append an empty variant row.
     */
    public function addVariant(): void
    {
        $this->variants[] = [
            'id' => null,
            'name' => '',
            'price' => '',
            'is_available' => true,
        ];
    }

    /**
     * Drop a variant row. A product must keep at least one.
     */
    public function removeVariant(int $index): void
    {
        unset($this->variants[$index]);

        $this->variants = array_values($this->variants);

        if ($this->variants === []) {
            $this->addVariant();
        }
    }

    /**
     * Persist the product and its variants.
     */
    public function save(): void
    {
        Gate::authorize('manage-products');

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('products', 'name')->ignore($this->productId),
            ],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'variants.*.is_available' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            $product = $this->productId === null
                ? new Product
                : Product::findOrFail($this->productId);

            $product->fill([
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'description' => $validated['description'] ?: null,
                'is_active' => $validated['is_active'],
            ])->save();

            // Only ids this product already owns may be updated — a submitted id
            // belonging to another product is treated as a new row, never adopted.
            $ownedIds = $product->variants()->pluck('id')->all();
            $keptIds = [];

            foreach ($validated['variants'] as $row) {
                $attributes = [
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'is_available' => $row['is_available'],
                ];

                $variant = in_array($row['id'] ?? null, $ownedIds, strict: true)
                    ? tap($product->variants()->findOrFail($row['id']))->update($attributes)
                    : $product->variants()->create($attributes);

                $keptIds[] = $variant->id;
            }

            $product->variants()->whereNotIn('id', $keptIds)->delete();

            $this->productId = $product->id;
        });

        Flux::toast(variant: 'success', text: __('Product saved.'));

        $this->redirectRoute('employee.products.index', navigate: true);
    }

    /**
     * Build a slug that no other product is already using.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Product::where('slug', $slug)->whereKeyNot($this->productId ?? 0)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">
        {{ $productId === null ? __('New product') : __('Edit product') }}
    </flux:heading>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex max-w-3xl flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" required autofocus />

        <flux:select wire:model="category_id" :label="__('Category')" required>
            <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive products stay out of the customer catalogue.')" />

        <flux:separator variant="subtle" />

        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Sizes and prices') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addVariant" type="button">
                    {{ __('Add size') }}
                </flux:button>
            </div>

            @error('variants')
                <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @enderror

            @foreach ($variants as $index => $variant)
                <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="variant-{{ $index }}">
                    <flux:input
                        wire:model="variants.{{ $index }}.name"
                        :label="__('Size')"
                        placeholder="Large (10x14)"
                        class="flex-1"
                    />

                    <flux:input
                        wire:model="variants.{{ $index }}.price"
                        :label="__('Price')"
                        type="number"
                        step="0.01"
                        min="0"
                        class="md:w-40"
                    />

                    <div class="flex items-center gap-3 md:pt-7">
                        <flux:switch wire:model="variants.{{ $index }}.is_available" :label="__('Available')" />

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="trash"
                            type="button"
                            wire:click="removeVariant({{ $index }})"
                            :disabled="count($variants) === 1"
                        />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save product') }}</flux:button>
            <flux:button variant="ghost" :href="route('employee.products.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
