<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Manage product')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public ?int $productId = null;

    /**
     * A menu-card photo on its way up, before savePhoto() decides whether it
     * meets the specification.
     *
     * @var TemporaryUploadedFile|null
     */
    public $upload = null;

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

        // Livewire skips the TrimStrings middleware, so a stray space would
        // otherwise be stored, and slip a duplicate name past unique or distinct.
        $this->name = trim($this->name);
        $this->description = trim($this->description);
        $this->variants = array_map(
            fn (array $variant): array => ['name' => trim((string) ($variant['name'] ?? ''))] + $variant,
            $this->variants,
        );

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
            'variants.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
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

    /**
     * The product and each of its saved sizes, with the picture each card
     * shows now and where it comes from: uploaded for it, built into the app,
     * or — for a size with neither of its own — the product's.
     *
     * @return list<array{key: string, variantId: int|null, label: string, url: string, uploaded: bool, source: string}>
     */
    #[Computed]
    public function photos(): array
    {
        if ($this->productId === null) {
            return [];
        }

        $product = Product::with(['category', 'photo', 'variants.photo'])->findOrFail($this->productId);
        $productUrl = $product->imageUrl();

        return [
            [
                'key' => 'product',
                'variantId' => null,
                'label' => __('The product'),
                'url' => $productUrl,
                'uploaded' => $product->photo !== null,
                'source' => $product->photo !== null ? __('Uploaded') : __('Built-in'),
            ],
            ...$product->variants->sortBy('id')->map(function (ProductVariant $size) use ($product, $productUrl): array {
                $url = $product->imageUrl($size);

                return [
                    'key' => (string) $size->id,
                    'variantId' => $size->id,
                    'label' => $size->name,
                    'url' => $url,
                    'uploaded' => $size->photo !== null,
                    'source' => match (true) {
                        $size->photo !== null => __('Uploaded'),
                        $url === $productUrl => __('Product\'s photo'),
                        default => __('Built-in'),
                    },
                ];
            })->values()->all(),
        ];
    }

    /**
     * Make the photo just uploaded the product's own, or one size's.
     *
     * It must meet the specification on ProductPhoto, or nothing changes and
     * the row says why. It replaces any photo uploaded there before.
     */
    public function savePhoto(?int $variantId = null): void
    {
        Gate::authorize('manage-products');

        $product = $this->photographedProduct($variantId);

        $this->resetErrorBag();

        $validator = Validator::make(
            ['upload' => $this->upload],
            ['upload' => ProductPhoto::rules()],
            ProductPhoto::messages('upload'),
        );

        if ($validator->fails()) {
            $this->reset('upload');
            $this->addError('photo.'.($variantId ?? 'product'), $validator->errors()->first('upload'));

            return;
        }

        DB::transaction(function () use ($product, $variantId): void {
            // Locked, so two uploads at once cannot both land.
            Product::whereKey($product->id)->lockForUpdate()->first();

            ProductPhoto::where('product_id', $product->id)->where('product_variant_id', $variantId)->delete();

            ProductPhoto::create([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'mime' => ProductPhoto::detectedMime($this->upload),
                'data' => base64_encode($this->upload->get()),
            ]);
        });

        $this->reset('upload');
        unset($this->photos);

        Flux::toast(variant: 'success', text: __('Photo uploaded.'));
    }

    /**
     * Take an uploaded photo down, so the card goes back to the picture that
     * ships with the app.
     */
    public function removePhoto(?int $variantId = null): void
    {
        Gate::authorize('manage-products');

        $product = $this->photographedProduct($variantId);

        ProductPhoto::where('product_id', $product->id)->where('product_variant_id', $variantId)->delete();

        $this->resetErrorBag();
        unset($this->photos);

        Flux::toast(variant: 'success', text: __('Photo removed.'));
    }

    /**
     * The product being edited, refusing a size that is not one of its own.
     */
    protected function photographedProduct(?int $variantId): Product
    {
        $product = Product::findOrFail($this->productId);

        abort_if($variantId !== null && ! $product->variants()->whereKey($variantId)->exists(), 404);

        return $product;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">
        {{ $productId === null ? __('New product') : __('Edit product') }}
    </flux:heading>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex max-w-3xl flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" maxlength="255" required autofocus />

        <flux:select wire:model="category_id" :label="__('Category')" required>
            <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:text class="-mt-4 text-sm">
            {{ __('Missing one?') }}
            <flux:link :href="route('employee.products.categories')" wire:navigate>{{ __('Add it under product categories') }}</flux:link>
            {{ __('first — anything typed here is not kept.') }}
        </flux:text>

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" maxlength="1000" />

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
                <flux:text class="text-danger">{{ $message }}</flux:text>
            @enderror

            @foreach ($variants as $index => $variant)
                <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700" wire:key="variant-{{ $index }}">
                    <flux:input
                        wire:model="variants.{{ $index }}.name"
                        :label="__('Size')"
                        maxlength="255"
                        placeholder="Large (10x14)"
                        class="flex-1"
                    />

                    <flux:input
                        wire:model="variants.{{ $index }}.price"
                        :label="__('Price')"
                        type="number"
                        step="0.01"
                        min="0"
                        max="9999999.99"
                        inputmode="decimal"
                        x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
                        x-on:input="if ($el.value > 9999999.99) { $el.value = 9999999.99; $el.dispatchEvent(new Event('input')) }"
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

    <flux:separator variant="subtle" class="my-8" />

    <div class="flex max-w-3xl flex-col gap-4" data-test="product-photos">
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">{{ __('Menu photos') }}</flux:heading>
            <flux:text>
                {{ __('The picture on each menu card. A size without a photo of its own shows the product\'s.') }}
            </flux:text>
            <flux:text class="text-sm font-medium">{{ \App\Models\ProductPhoto::specification() }}</flux:text>
        </div>

        @if ($productId === null)
            <flux:text class="rounded-lg bg-zinc-100 p-4 text-sm dark:bg-zinc-800">
                {{ __('Save the product first, then add its photos.') }}
            </flux:text>
        @else
            @error('upload')
                <flux:text class="text-danger">{{ $message }}</flux:text>
            @enderror

            @foreach ($this->photos as $row)
                <div
                    class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-start dark:border-zinc-700"
                    wire:key="photo-{{ $row['key'] }}"
                    x-data="{ uploading: false }"
                >
                    <img
                        src="{{ $row['url'] }}"
                        alt="{{ __('The menu card picture for :label', ['label' => $row['label']]) }}"
                        width="240"
                        height="180"
                        loading="lazy"
                        class="aspect-4/3 w-full rounded-md border border-zinc-200 bg-white object-cover md:w-40 dark:border-zinc-700"
                    >

                    <div class="flex flex-1 flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <flux:heading>{{ $row['label'] }}</flux:heading>
                            <flux:badge size="sm" :color="$row['uploaded'] ? 'green' : 'zinc'">
                                {{ $row['source'] }}
                            </flux:badge>
                        </div>

                        {{-- Uploads as soon as one is picked; savePhoto() holds it to
                             the specification and says why if it falls short. --}}
                        <input
                            type="file"
                            accept="image/jpeg,image/webp"
                            aria-label="{{ __('Upload a photo for :label', ['label' => $row['label']]) }}"
                            x-on:change="
                                const file = $event.target.files[0];
                                $event.target.value = '';
                                if (! file) return;
                                uploading = true;
                                $wire.upload('upload', file,
                                    () => $wire.savePhoto({{ $row['variantId'] ?? 'null' }}).finally(() => uploading = false),
                                    () => uploading = false,
                                );
                            "
                            class="text-sm text-zinc-600 file:me-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100"
                        >
                        {{-- Hidden as served, so a re-render never leaves it showing. --}}
                        <p x-show="uploading" style="display: none" class="text-xs text-zinc-500">{{ __('Uploading…') }}</p>

                        @error('photo.'.$row['key'])
                            <flux:text class="text-danger">{{ $message }}</flux:text>
                        @enderror

                        @if ($row['uploaded'])
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="self-start"
                                wire:click="removePhoto({{ $row['variantId'] ?? 'null' }})"
                                wire:confirm="{{ __('Remove this photo? The card goes back to its built-in picture.') }}"
                            >
                                {{ __('Remove photo') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</section>
