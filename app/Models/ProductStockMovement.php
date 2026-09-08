<?php

namespace App\Models;

use App\Enums\ProductStockMovementType;
use Carbon\CarbonImmutable;
use Database\Factories\ProductStockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a location's finished-goods ledger.
 *
 * Append-only, like the ingredient ledger: a mistake is corrected by recording
 * the opposite adjustment, never by editing history, so stock always reconciles
 * with what happened.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $outlet_id
 * @property ProductStockMovementType $type
 * @property int $quantity
 * @property int|null $production_run_id
 * @property int|null $restock_id
 * @property int|null $sale_id
 * @property int $recorded_by
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'product_variant_id',
    'outlet_id',
    'type',
    'quantity',
    'production_run_id',
    'restock_id',
    'sale_id',
    'recorded_by',
    'note',
    'occurred_at',
])]
class ProductStockMovement extends Model
{
    /** @use HasFactory<ProductStockMovementFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductStockMovementType::class,
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * What moved.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Where it moved.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * The bake it came out of, when it came from one.
     *
     * @return BelongsTo<ProductionRun, $this>
     */
    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    /**
     * The restock it travelled on, when it was one half of a transfer.
     *
     * @return BelongsTo<Restock, $this>
     */
    public function restock(): BelongsTo
    {
        return $this->belongsTo(Restock::class);
    }

    /**
     * The sale it was rung up on, when one took it off the shelf.
     *
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
     * Limit the query to one location's shelf.
     *
     * @param  Builder<ProductStockMovement>  $query
     */
    #[Scope]
    protected function at(Builder $query, Outlet $outlet): void
    {
        $query->where('outlet_id', $outlet->id);
    }

    /**
     * Whether this movement added stock.
     */
    public function isIncrease(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * The quantity, with the sign that shows which way it went.
     */
    public function signedQuantity(): string
    {
        return ($this->quantity > 0 ? '+' : '−').abs($this->quantity);
    }
}
