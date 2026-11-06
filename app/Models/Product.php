<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['category_id', 'name', 'slug', 'description', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The category this product is filed under.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The sellable sizes or forms of this product.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The photo a manager uploaded for the product as a whole, without its
     * bytes — enough to say whether there is one and where it is served.
     *
     * @return HasOne<ProductPhoto, $this>
     */
    public function photo(): HasOne
    {
        return $this->hasOne(ProductPhoto::class)
            ->whereNull('product_variant_id')
            ->select(ProductPhoto::SUMMARY_COLUMNS);
    }

    /**
     * The storefront picture for this product, or for one size of it.
     *
     * The most particular picture wins: a photo of the size itself, then one
     * of the product, then a drawing. At each step a photo a manager uploaded
     * comes before the one that ships with the app under public/images/products
     * — "{slug}-{shape}.webp" for a size whose shape was photographed on its
     * own (the heart, the tincan), "{slug}.webp" for the product. A product
     * nobody has photographed or drawn yet borrows its category's drawing, and
     * failing that a generic one — so the card never shows a broken image.
     *
     * Needs the product's photo, and the size's, loaded.
     */
    public function imageUrl(?ProductVariant $size = null): string
    {
        if ($size?->photo !== null) {
            return $size->photo->url();
        }

        if (($shape = $size?->shape()) !== null && ($url = $this->shippedImageUrl("{$this->slug}-{$shape}.webp")) !== null) {
            return $url;
        }

        if ($this->photo !== null) {
            return $this->photo->url();
        }

        return $this->shippedImageUrl("{$this->slug}.webp")
            ?? $this->shippedImageUrl("{$this->slug}.svg")
            ?? $this->category->imageUrl();
    }

    /**
     * The address of a picture that ships with the app, if it does.
     */
    protected function shippedImageUrl(string $name): ?string
    {
        return is_file(public_path($path = "images/products/{$name}")) ? asset($path) : null;
    }

    /**
     * Limit the query to products published in the catalogue.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
