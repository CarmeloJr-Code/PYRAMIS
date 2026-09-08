<?php

namespace App\Models;

use App\Concerns\GeneratesReference;
use Carbon\CarbonImmutable;
use Database\Factories\ProductionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One baking run: what was made, how much of it, and what it took.
 *
 * The run is the record of finished-product output. What it consumed lives in
 * the inventory ledger pointing back at it, so consumption is never recomputed
 * from the recipe — editing a recipe afterwards cannot rewrite what a past bake
 * actually used.
 *
 * @property int $id
 * @property string $reference
 * @property int $product_variant_id
 * @property int $recipe_id
 * @property int $quantity
 * @property int $recorded_by
 * @property string|null $notes
 * @property CarbonImmutable $produced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'recipe_id', 'quantity', 'recorded_by', 'notes', 'produced_at'])]
class ProductionRun extends Model
{
    /** @use HasFactory<ProductionRunFactory> */
    use GeneratesReference, HasFactory;

    /**
     * Production runs get their own prefix so a reference is self-identifying.
     */
    protected static function referencePrefix(): string
    {
        return 'PD-';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'produced_at' => 'datetime',
        ];
    }

    /**
     * The size that was made.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The recipe it was costed against.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * The employee who logged it.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * What it took out of the stockroom.
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * How many units came out of the oven over a stretch of days.
     */
    public static function unitsProduced(string $from, string $to): int
    {
        return (int) static::query()
            ->whereDate('produced_at', '>=', $from)
            ->whereDate('produced_at', '<=', $to)
            ->sum('quantity');
    }

    /**
     * Limit the query to runs of a given day.
     *
     * @param  Builder<ProductionRun>  $query
     */
    #[Scope]
    protected function producedOn(Builder $query, string $date): void
    {
        $query->whereDate('produced_at', $date);
    }
}
