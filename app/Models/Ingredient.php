<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
use App\Enums\IngredientUnit;
use Carbon\CarbonImmutable;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string $reorder_level
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'unit', 'reorder_level', 'is_active'])]
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
}
