<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that left the business, and what it was for.
 *
 * @property int $id
 * @property int $expense_category_id
 * @property int $outlet_id
 * @property string $amount
 * @property CarbonImmutable $spent_on
 * @property string $description
 * @property int $recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['expense_category_id', 'outlet_id', 'amount', 'spent_on', 'description', 'recorded_by'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_on' => 'date',
        ];
    }

    /**
     * What it was for.
     *
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Where it belongs (FR-08).
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * The employee who filed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Limit the query to a stretch of days, inclusive of both ends.
     *
     * whereDate rather than whereBetween: the column is a date but is stored
     * with a midnight time, so comparing it to a bare Y-m-d upper bound would
     * drop everything filed on the last day of the range.
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function spentBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to);
    }

    /**
     * The amount in centavos.
     *
     * Integer centavos rather than bcmath: the production image does not
     * install bcmath, so bcadd would fatal on Render while passing locally.
     */
    public function amountInCentavos(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
