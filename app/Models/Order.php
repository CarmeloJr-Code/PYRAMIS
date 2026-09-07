<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use HasFactory;

    /**
     * Characters a reference is built from — Crockford-style, with I, L, O, U,
     * 0 and 1 removed so a reference can be read aloud without ambiguity.
     */
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * How many random characters follow the prefix. Thirty symbols to the tenth
     * power is roughly 2^49, so walking the space is impractical — that is what
     * stands in for an account (BR-001).
     */
    private const REFERENCE_LENGTH = 10;

    /**
     * Assign a reference before the row is written, so no code path can persist
     * an order without one.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            // getAttribute, not the property: before the row exists the
            // attribute may simply be absent, which the persisted-shape
            // docblock does not describe.
            if (blank($order->getAttribute('reference'))) {
                $order->reference = static::generateReference();
            }
        });
    }

    /**
     * Build a reference no other order is already using.
     */
    public static function generateReference(): string
    {
        do {
            $suffix = '';

            for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
                $suffix .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }

            $reference = 'PY-'.$suffix;
        } while (static::where('reference', $reference)->exists());

        return $reference;
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
