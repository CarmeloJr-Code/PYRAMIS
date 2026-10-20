<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'slug'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * The products filed under this category.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The storefront illustration for this category.
     *
     * The artwork ships with the app under public/images/categories, named by
     * slug. A category nobody has drawn yet falls back to the generic one, so
     * the card never shows a broken image.
     */
    public function imageUrl(): string
    {
        if (is_file(public_path($path = "images/categories/{$this->slug}.svg"))) {
            return asset($path);
        }

        return asset('images/product.svg');
    }
}
