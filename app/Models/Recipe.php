<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one sellable size is made of.
 *
 * Quantities are held per batch, because that is how the business writes them
 * down — "good for 7 round pans". What a run of some other number of units
 * takes is scaled from the batch rather than stored a second time.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $yield_quantity
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'yield_quantity', 'notes'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yield_quantity' => 'integer',
        ];
    }

    /**
     * The size this recipe makes.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * What it calls for.
     *
     * @return HasMany<RecipeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * What producing the given number of units takes, in thousandths, keyed by
     * ingredient id.
     *
     * Scaled from the batch and rounded to the thousandth the ledger holds, so
     * a requirement and the movement that satisfies it are the same figure. A
     * part batch is allowed: bakers routinely make half of one.
     *
     * @return array<int, int>
     */
    public function requirementsInThousandths(int $units): array
    {
        $requirements = [];

        foreach ($this->items as $item) {
            /** @var RecipeItem $item */
            $requirements[$item->ingredient_id] = (int) round(
                $item->quantityInThousandths() * $units / $this->yield_quantity,
            );
        }

        return $requirements;
    }
}
