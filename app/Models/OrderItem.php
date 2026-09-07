<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property string $unit_price
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'quantity', 'unit_price'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * The order this line belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The size that was ordered.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * What this line costs, in centavos — quantity times the price captured at
     * order time.
     *
     * Integer centavos rather than bcmath: the production image installs only
     * pdo_pgsql, intl, zip, opcache and pcntl, so bcmul would fatal on Render
     * while passing locally.
     */
    public function subtotalInCentavos(): int
    {
        return (int) round((float) $this->unit_price * 100) * $this->quantity;
    }
}
