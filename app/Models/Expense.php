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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * What was spent over a stretch of days, in centavos.
     *
     * Summed in SQL for the same reason takings are.
     */
    public static function totalSpentInCentavos(string $from, string $to, ?int $outletId = null): int
    {
        $total = static::query()
            ->spentBetween($from, $to)
            ->when($outletId !== null, fn (Builder $scoped) => $scoped->where('outlet_id', $outletId))
            ->sum('amount');

        return (int) round((float) $total * 100);
    }

    /**
     * Spending broken down by heading, biggest first.
     *
     * @return Collection<int, array{name: string, entries: int, total: int}>
     */
    public static function totalsByCategory(string $from, string $to, ?int $outletId = null): Collection
    {
        return DB::table('expenses')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereDate('spent_on', '>=', $from)
            ->whereDate('spent_on', '<=', $to)
            ->when($outletId !== null, fn (QueryBuilder $query) => $query->where('expenses.outlet_id', $outletId))
            ->groupBy('expense_categories.id')
            ->orderByDesc('total')
            ->select([
                'expense_categories.name as name',
                DB::raw('count(*) as entries'),
                DB::raw('sum(expenses.amount) as total'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'entries' => (int) $row->entries,
                'total' => (int) round((float) $row->total * 100),
            ]);
    }

    /**
     * Spending broken down by location, keyed by outlet so an outlet report can
     * look itself up.
     *
     * @return Collection<int, array{outlet_id: int, name: string, entries: int, total: int}>
     */
    public static function totalsByOutlet(string $from, string $to): Collection
    {
        return DB::table('expenses')
            ->join('outlets', 'outlets.id', '=', 'expenses.outlet_id')
            ->whereDate('spent_on', '>=', $from)
            ->whereDate('spent_on', '<=', $to)
            ->groupBy('outlets.id')
            ->orderByDesc('total')
            ->select([
                'outlets.id as outlet_id',
                'outlets.name as name',
                DB::raw('count(*) as entries'),
                DB::raw('sum(expenses.amount) as total'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'outlet_id' => (int) $row->outlet_id,
                'name' => (string) $row->name,
                'entries' => (int) $row->entries,
                'total' => (int) round((float) $row->total * 100),
            ]);
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
