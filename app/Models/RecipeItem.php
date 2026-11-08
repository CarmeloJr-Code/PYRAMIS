<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
use App\Enums\RecipeMeasure;
use Carbon\CarbonImmutable;
use Database\Factories\RecipeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient a recipe calls for, per batch.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $ingredient_id
 * @property string $quantity
 * @property RecipeMeasure $measure
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ingredient_id', 'quantity', 'measure'])]
class RecipeItem extends Model
{
    /** @use HasFactory<RecipeItemFactory> */
    use FormatsQuantities, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'measure' => RecipeMeasure::class,
        ];
    }

    /**
     * The recipe this line belongs to.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * What it calls for.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * The per-batch quantity as written, in thousandths of its own measure.
     */
    public function quantityInThousandths(): int
    {
        return static::quantityToThousandths($this->quantity);
    }

    /**
     * The per-batch quantity as written, formatted.
     */
    public function amount(): string
    {
        return static::formatQuantity($this->quantityInThousandths());
    }

    /**
     * What one batch draws from stock, in thousandths of the ingredient's
     * stocked unit, before rounding.
     *
     * @throws \RuntimeException when the ingredient has no conversion for this line's measure
     */
    public function stockThousandthsPerBatch(): float
    {
        return $this->ingredient->toStockThousandths($this->quantityInThousandths(), $this->measure);
    }
}
