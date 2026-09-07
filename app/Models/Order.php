<?php

namespace App\Models;

use App\Concerns\GeneratesReference;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @property int $id
 * @property string $reference
 * @property int $outlet_id
 * @property string $customer_name
 * @property string $customer_phone
 * @property string|null $customer_email
 * @property CarbonImmutable $pickup_at
 * @property string|null $notes
 * @property OrderStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'outlet_id',
    'customer_name',
    'customer_phone',
    'customer_email',
    'pickup_at',
    'notes',
    'status',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use GeneratesReference, HasFactory;

    /**
     * The reference is what stands in for a customer account (BR-001), so it
     * must not be enumerable.
     */
    protected static function referencePrefix(): string
    {
        return 'PY-';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pickup_at' => 'datetime',
            'status' => OrderStatus::class,
        ];
    }

    /**
     * Customers reach their order by reference, never by id.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * The outlet this order is collected from.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * The lines making up this order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The sale raised when this order was collected, if it has been.
     *
     * @return HasOne<Sale, $this>
     */
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /**
     * The order total, derived from its lines rather than stored, so there is
     * one source of truth and no drift (docs/database.md).
     */
    public function total(): string
    {
        $centavos = $this->items->sum(
            fn (OrderItem $item): int => $item->subtotalInCentavos(),
        );

        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Move the order to a new status, refusing anything the workflow does not
     * allow.
     *
     * Enforced here rather than only in the component, so no code path — a
     * crafted Livewire call included — can skip ahead, walk backwards, or
     * reopen a finished order.
     *
     * @throws RuntimeException when the move is not permitted
     */
    public function transitionTo(OrderStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw new RuntimeException(
                "Cannot move order {$this->reference} from {$this->status->value} to {$status->value}.",
            );
        }

        $this->update(['status' => $status]);
    }

    /**
     * A reference formatted for display.
     */
    public function formattedReference(): string
    {
        return Str::upper($this->reference);
    }
}
