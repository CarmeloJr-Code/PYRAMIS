<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RestockItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One size on a restock: what was asked for, and what was actually set aside.
 *
 * The two are kept apart because a bake can come up short. The prepared figure
 * is what travels, and what the ledger records.
 *
 * @property int $id
 * @property int $restock_id
 * @property int $product_variant_id
 * @property int $quantity_requested
 * @property int|null $quantity_prepared
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'quantity_requested', 'quantity_prepared'])]
class RestockItem extends Model
{
    /** @use HasFactory<RestockItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_prepared' => 'integer',
        ];
    }

    /**
     * The restock this line belongs to.
     *
     * @return BelongsTo<Restock, $this>
     */
    public function restock(): BelongsTo
    {
        return $this->belongsTo(Restock::class);
    }

    /**
     * The size being sent.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * What will actually travel — the prepared figure once there is one, and
     * what was asked for until then.
     */
    public function travellingQuantity(): int
    {
        return $this->quantity_prepared ?? $this->quantity_requested;
    }
}
