<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
use App\Enums\IngredientUnit;
use App\Enums\InventoryMovementType;
use Carbon\CarbonImmutable;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One entry in an ingredient's stock ledger.
 *
 * The ledger is append-only. Nothing edits or deletes a movement — a mistake
 * is corrected by recording the opposite adjustment, so the history stays
 * auditable and stock still reconciles.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property InventoryMovementType $type
 * @property string $quantity
 * @property int $recorded_by
 * @property int|null $production_run_id
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ingredient_id', 'type', 'quantity', 'recorded_by', 'production_run_id', 'note', 'occurred_at'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use FormatsQuantities, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * What moved.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * The bake that consumed it, when it came from one rather than being
     * recorded by hand at the stockroom.
     *
     * @return BelongsTo<ProductionRun, $this>
     */
    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    /**
     * The employee who recorded it.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The signed quantity, in thousandths.
     */
    public function quantityInThousandths(): int
    {
        return static::quantityToThousandths($this->quantity);
    }

    /**
     * Whether this movement added stock.
     */
    public function isIncrease(): bool
    {
        return $this->quantityInThousandths() > 0;
    }

    /**
     * The quantity, unsigned — for a listing whose column already says which
     * way it went.
     */
    public function magnitude(): string
    {
        return static::formatQuantity(abs($this->quantityInThousandths()));
    }

    /**
     * The quantity, formatted with the sign that shows which way it went.
     */
    public function signedQuantity(): string
    {
        $thousandths = $this->quantityInThousandths();

        return ($thousandths > 0 ? '+' : '−').static::formatQuantity(abs($thousandths));
    }

    /**
     * Limit the query to movements over a stretch of days, both ends included.
     *
     * @param  Builder<InventoryMovement>  $query
     */
    #[Scope]
    protected function occurredBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('occurred_at', '>=', $from)->whereDate('occurred_at', '<=', $to);
    }

    /**
     * What moved through the stockroom over a stretch of days, by ingredient.
     *
     * The three directions are separated in one pass of conditional sums
     * rather than three queries, and the totals are thousandths so they can be
     * formatted the same way stock is everywhere else.
     *
     * @return Collection<int, array{ingredient_id: int, name: string, unit: IngredientUnit, entries: int, received: int, used: int, adjusted: int}>
     */
    public static function summaryByIngredient(string $from, string $to): Collection
    {
        $sumWhere = fn (InventoryMovementType $type): string => sprintf(
            "sum(case when inventory_movements.type = '%s' then inventory_movements.quantity else 0 end)",
            $type->value,
        );

        return DB::table('inventory_movements')
            ->join('ingredients', 'ingredients.id', '=', 'inventory_movements.ingredient_id')
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to)
            ->groupBy('ingredients.id')
            ->orderBy('ingredients.name')
            ->select([
                'ingredients.id as ingredient_id',
                'ingredients.name as name',
                'ingredients.unit as unit',
                DB::raw('count(*) as entries'),
                DB::raw($sumWhere(InventoryMovementType::Received).' as received'),
                DB::raw($sumWhere(InventoryMovementType::Usage).' as used'),
                DB::raw($sumWhere(InventoryMovementType::Adjustment).' as adjusted'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'ingredient_id' => (int) $row->ingredient_id,
                'name' => (string) $row->name,
                'unit' => IngredientUnit::from((string) $row->unit),
                'entries' => (int) $row->entries,
                'received' => static::quantityToThousandths((float) ($row->received ?? 0)),
                'used' => static::quantityToThousandths((float) ($row->used ?? 0)),
                'adjusted' => static::quantityToThousandths((float) ($row->adjusted ?? 0)),
            ]);
    }
}
