<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ingredient_id', 'quantity'])]
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
     * The per-batch quantity, in thousandths.
     */
    public function quantityInThousandths(): int
    {
        return static::quantityToThousandths($this->quantity);
    }

    /**
     * The per-batch quantity, formatted.
     */
    public function amount(): string
    {
        return static::formatQuantity($this->quantityInThousandths());
    }
}
