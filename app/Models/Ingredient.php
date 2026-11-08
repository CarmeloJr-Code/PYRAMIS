<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
use App\Enums\IngredientUnit;
use App\Enums\RecipeMeasure;
use App\Enums\StockLevel;
use Carbon\CarbonImmutable;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A raw material the bakery holds stock of.
 *
 * The record carries what the ingredient is, not how much of it there is:
 * stock is the sum of the movements below it, so a quantity can always be
 * traced to the transactions that produced it (Phase 5 exit criterion).
 *
 * @property int $id
 * @property string $name
 * @property IngredientUnit $unit
 * @property string|null $content_per_unit
 * @property IngredientUnit|null $content_measure
 * @property string|null $cup_weight
 * @property string $reorder_level
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'unit', 'content_per_unit', 'content_measure', 'cup_weight', 'reorder_level', 'is_active'])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use FormatsQuantities, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'content_per_unit' => 'decimal:3',
            'content_measure' => IngredientUnit::class,
            'cup_weight' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every stock change this ingredient has ever seen.
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Limit the query to ingredients still in use.
     *
     * @param  Builder<Ingredient>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Aggregate the ledger in SQL, so a stock listing stays one query.
     *
     * @param  Builder<Ingredient>  $query
     */
    #[Scope]
    protected function withStock(Builder $query): void
    {
        $query->withSum('movements as stock_sum', 'quantity');
    }

    /**
     * Current stock, in thousandths.
     *
     * Uses the aggregate the withStock scope selected when it is there, and
     * falls back to its own query when it is not, so a bare model still
     * answers correctly.
     */
    public function stockInThousandths(): int
    {
        // array_key_exists, not ??: the aggregate is null for an ingredient with
        // no movements, and falling through to a query there would put one
        // behind every empty row of a listing.
        $quantity = array_key_exists('stock_sum', $this->attributes)
            ? $this->attributes['stock_sum']
            : $this->movements()->sum('quantity');

        return static::quantityToThousandths($quantity ?? 0);
    }

    /**
     * Current stock, formatted.
     */
    public function stock(): string
    {
        return static::formatQuantity($this->stockInThousandths());
    }

    /**
     * The level at or below which stock counts as low, in thousandths.
     */
    public function reorderLevelInThousandths(): int
    {
        return static::quantityToThousandths($this->reorder_level);
    }

    /**
     * The reorder level, formatted.
     */
    public function reorderLevel(): string
    {
        return static::formatQuantity($this->reorderLevelInThousandths());
    }

    /**
     * Whether stock is weighed in grams or poured in milliliters, or null when a
     * pack, can or piece has not been told what it holds.
     */
    public function baseMeasure(): ?IngredientUnit
    {
        return $this->unit->baseMeasure() ?? $this->content_measure;
    }

    /**
     * How many thousandths of a gram or milliliter one stocked unit holds, or
     * null when that is not known.
     */
    public function baseThousandthsPerUnit(): ?int
    {
        $factor = $this->unit->baseFactor();

        if ($factor !== null) {
            return $factor * 1000;
        }

        if ($this->content_measure === null || $this->content_per_unit === null) {
            return null;
        }

        $content = static::quantityToThousandths($this->content_per_unit);

        return $content > 0 ? $content : null;
    }

    /**
     * What one level cup weighs, in thousandths of the base measure, or null
     * when it has not been set.
     */
    public function cupWeightInThousandths(): ?int
    {
        if ($this->cup_weight === null) {
            return null;
        }

        $weight = static::quantityToThousandths($this->cup_weight);

        return $weight > 0 ? $weight : null;
    }

    /**
     * The measures a recipe may write this ingredient in.
     *
     * The stocked unit always works. Grams or milliliters need to know what one
     * unit holds; cups and spoons need that and the weight of a cup.
     *
     * @return array<int, RecipeMeasure>
     */
    public function measuresAvailable(): array
    {
        $measures = [RecipeMeasure::Unit];

        if ($this->baseThousandthsPerUnit() === null) {
            return $measures;
        }

        $measures[] = RecipeMeasure::Base;

        if ($this->cupWeightInThousandths() !== null) {
            array_push($measures, RecipeMeasure::Cup, RecipeMeasure::Tablespoon, RecipeMeasure::Teaspoon);
        }

        return $measures;
    }

    /**
     * Whether a recipe may write this ingredient in the given measure.
     */
    public function allowsMeasure(RecipeMeasure $measure): bool
    {
        return in_array($measure, $this->measuresAvailable(), strict: true);
    }

    /**
     * Convert a quantity written in a recipe measure to thousandths of the
     * stocked unit, unrounded.
     *
     * Unrounded so a recipe can scale it before the single rounding to the
     * ledger's thousandth; rounding a teaspoon first and then multiplying would
     * compound the error.
     *
     * @throws RuntimeException when this ingredient cannot be written in that measure
     */
    public function toStockThousandths(int $quantityInThousandths, RecipeMeasure $measure): float
    {
        if ($measure === RecipeMeasure::Unit) {
            return (float) $quantityInThousandths;
        }

        if (! $this->allowsMeasure($measure)) {
            throw new RuntimeException(
                "{$this->name} has no conversion for {$measure->abbreviation($this)} — set it on the ingredient first.",
            );
        }

        // Thousandths of a gram or milliliter.
        $base = $measure === RecipeMeasure::Base
            ? (float) $quantityInThousandths
            : $quantityInThousandths * $this->cupWeightInThousandths() / 1000 / $measure->perCup();

        return $base * 1000 / $this->baseThousandthsPerUnit();
    }

    /**
     * Whether nothing is left.
     */
    public function isOutOfStock(): bool
    {
        return $this->stockInThousandths() <= 0;
    }

    /**
     * Whether stock has fallen to the level the business set.
     *
     * A zero reorder level means none was set, so the ingredient is never
     * reported as low — otherwise everything unconfigured would shout.
     */
    public function isLowStock(): bool
    {
        $reorderLevel = $this->reorderLevelInThousandths();

        return $reorderLevel > 0 && $this->stockInThousandths() <= $reorderLevel;
    }

    /**
     * Where this ingredient's stock stands: retired first, then empty, then
     * low, otherwise fine.
     */
    public function stockLevel(): StockLevel
    {
        return match (true) {
            ! $this->is_active => StockLevel::NotStocked,
            $this->isOutOfStock() => StockLevel::OutOfStock,
            $this->isLowStock() => StockLevel::Low,
            default => StockLevel::InStock,
        };
    }
}
