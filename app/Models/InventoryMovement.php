<?php

namespace App\Models;

use App\Concerns\FormatsQuantities;
use App\Enums\InventoryMovementType;
use Carbon\CarbonImmutable;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ingredient_id', 'type', 'quantity', 'recorded_by', 'note', 'occurred_at'])]
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
}
