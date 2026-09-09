<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProductVariantFactory;
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
 * @property int $product_id
 * @property string $name
 * @property string $price
 * @property bool $is_available
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_id', 'name', 'price', 'is_available'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    /**
     * The product this variant is a size or form of.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What this size is made of, once the business has written it down.
     *
     * @return HasOne<Recipe, $this>
     */
    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }

    /**
     * Every finished-goods movement of this size, at any location.
     *
     * @return HasMany<ProductStockMovement, $this>
     */
    public function productStockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    /**
     * Limit the query to variants the bakery can currently sell.
     *
     * @param  Builder<ProductVariant>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('is_available', true);
    }

    /**
     * Aggregate one location's shelf in SQL, so a stock listing stays one query.
     *
     * @param  Builder<ProductVariant>  $query
     */
    #[Scope]
    protected function withStockAt(Builder $query, Outlet $outlet): void
    {
        $query->withSum([
            'productStockMovements as stock_sum' => fn (Builder $movements) => $movements->where('outlet_id', $outlet->id),
        ], 'quantity');
    }

    /**
     * Aggregate every location's shelf at once.
     *
     * What the business holds of a size, wherever it is standing — which is
     * what a demand outlook weighs projected demand against, since goods can
     * be moved between locations (BR-004).
     *
     * @param  Builder<ProductVariant>  $query
     */
    #[Scope]
    protected function withStockEverywhere(Builder $query): void
    {
        $query->withSum('productStockMovements as stock_sum', 'quantity');
    }

    /**
     * The stock the withStockAt scope selected.
     *
     * array_key_exists, not ??: the aggregate is null for a size with no
     * movements at that location, and falling through to a query there would
     * put one behind every empty row of a listing.
     */
    public function stockInUnits(): int
    {
        return (int) ($this->attributes['stock_sum'] ?? 0);
    }

    /**
     * How many of this size are on the shelf at the given location.
     */
    public function stockAt(Outlet $outlet): int
    {
        return (int) $this->productStockMovements()
            ->where('outlet_id', $outlet->id)
            ->sum('quantity');
    }
}
