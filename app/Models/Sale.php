<?php

namespace App\Models;

use App\Concerns\GeneratesReference;
use App\Enums\SaleStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $reference
 * @property int $outlet_id
 * @property int|null $order_id
 * @property int $recorded_by
 * @property SaleStatus $status
 * @property CarbonImmutable $sold_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['outlet_id', 'order_id', 'recorded_by', 'status', 'sold_at'])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use GeneratesReference, HasFactory;

    /**
     * Sales get their own prefix so a reference is self-identifying.
     */
    protected static function referencePrefix(): string
    {
        return 'SL-';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Where the sale happened.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * The pre-order this sale settled, if it came from one.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * What was sold.
     *
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Limit the query to sales that count towards takings.
     *
     * @param  Builder<Sale>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', SaleStatus::Completed);
    }

    /**
     * Whether this sale still counts.
     */
    public function isVoided(): bool
    {
        return $this->status === SaleStatus::Voided;
    }

    /**
     * Void the sale rather than deleting it, so the record stays auditable.
     */
    public function void(): void
    {
        if ($this->isVoided()) {
            return;
        }

        // Set directly rather than via update(): voided_at is deliberately not
        // mass-assignable, because void() is the only thing that may set it.
        $this->status = SaleStatus::Voided;
        $this->voided_at = now();

        $this->save();
    }

    /**
     * The sale total in centavos, from its own lines.
     */
    public function totalInCentavos(): int
    {
        return $this->items->sum(
            fn (SaleItem $item): int => $item->subtotalInCentavos(),
        );
    }

    /**
     * The sale total, formatted in pesos.
     */
    public function total(): string
    {
        return number_format($this->totalInCentavos() / 100, 2, '.', '');
    }
}
