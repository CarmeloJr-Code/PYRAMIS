<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The GCash payment behind a pre-order: the proof the customer sent, and the
 * cashier's check of what actually arrived.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $gcash_reference
 * @property string|null $receipt
 * @property string|null $receipt_mime
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $amount
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'gcash_reference',
    'receipt',
    'receipt_mime',
    'submitted_at',
    'amount',
    'verified_by',
    'verified_at',
])]
#[Hidden(['receipt'])]
class OrderPayment extends Model
{
    /** @use HasFactory<OrderPaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The order this payment is for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The cashier who checked the payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Everything but the screenshot itself, which is megabytes of base64 that
     * only the receipt route ever needs.
     *
     * @param  Builder<OrderPayment>  $query
     */
    #[Scope]
    protected function withoutReceipt(Builder $query): void
    {
        $query->select([
            'id', 'order_id', 'gcash_reference', 'receipt_mime', 'submitted_at',
            'amount', 'verified_by', 'verified_at', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Whether a cashier has checked the money arrived.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether the customer sent a screenshot of their receipt.
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_mime !== null;
    }
}
