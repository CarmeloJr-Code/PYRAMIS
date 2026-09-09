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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * What this sale took off the outlet's shelf, and put back if it was
     * voided.
     *
     * @return HasMany<ProductStockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
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
     * The sale lines that count, over a stretch of days.
     *
     * Every figure a sales report shows is this same set grouped a different
     * way, so "counts" — completed, in range, and optionally at one outlet —
     * is decided once here rather than restated per report.
     */
    private static function completedLines(string $from, string $to, ?int $outletId = null): QueryBuilder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereDate('sales.sold_at', '>=', $from)
            ->whereDate('sales.sold_at', '<=', $to)
            ->when($outletId !== null, fn (QueryBuilder $query) => $query->where('sales.outlet_id', $outletId));
    }

    /**
     * A decimal total as whole centavos.
     */
    private static function centavos(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * What was taken over a stretch of days, in centavos.
     *
     * Summed in SQL rather than by loading the sales and adding them up: a
     * dashboard reads a month at a time and must not pull it into memory to do
     * so. Voided sales are excluded, as everywhere else.
     */
    public static function takingsInCentavos(string $from, string $to, ?int $outletId = null): int
    {
        return self::centavos(
            self::completedLines($from, $to, $outletId)
                ->sum(DB::raw('sale_items.quantity * sale_items.unit_price')),
        );
    }

    /**
     * Takings day by day — the series behind both "daily sales" and the trend.
     *
     * @return Collection<int, array{day: string, sales: int, units: int, takings: int}>
     */
    public static function dailyTakings(string $from, string $to, ?int $outletId = null): Collection
    {
        // date() rather than a cast: both SQLite and Postgres have it and both
        // return the day as a plain string, where CAST(... AS DATE) would give
        // SQLite numeric affinity and hand back the year.
        return self::completedLines($from, $to, $outletId)
            ->groupBy(DB::raw('date(sales.sold_at)'))
            ->orderBy('day')
            ->select([
                DB::raw('date(sales.sold_at) as day'),
                DB::raw('count(distinct sales.id) as sales'),
                DB::raw('sum(sale_items.quantity) as units'),
                DB::raw('sum(sale_items.quantity * sale_items.unit_price) as takings'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                'sales' => (int) $row->sales,
                'units' => (int) $row->units,
                'takings' => self::centavos($row->takings),
            ]);
    }

    /**
     * What sold, biggest earner first.
     *
     * @return Collection<int, array{product: string, size: string, units: int, takings: int}>
     */
    public static function takingsByProduct(string $from, string $to, ?int $outletId = null): Collection
    {
        return self::completedLines($from, $to, $outletId)
            ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            // Grouped by key, not by name: two products may legitimately share
            // one, and merging them would report a size that never sold.
            ->groupBy('product_variants.id', 'products.id')
            ->orderByDesc('takings')
            ->select([
                'products.name as product',
                'product_variants.name as size',
                DB::raw('sum(sale_items.quantity) as units'),
                DB::raw('sum(sale_items.quantity * sale_items.unit_price) as takings'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'product' => (string) $row->product,
                'size' => (string) $row->size,
                'units' => (int) $row->units,
                'takings' => self::centavos($row->takings),
            ]);
    }

    /**
     * Where it sold, keyed by outlet so an outlet report can look itself up.
     *
     * @return Collection<int, array{outlet_id: int, name: string, sales: int, units: int, takings: int}>
     */
    public static function takingsByOutlet(string $from, string $to): Collection
    {
        return self::completedLines($from, $to)
            ->join('outlets', 'outlets.id', '=', 'sales.outlet_id')
            ->groupBy('outlets.id')
            ->orderByDesc('takings')
            ->select([
                'outlets.id as outlet_id',
                'outlets.name as name',
                DB::raw('count(distinct sales.id) as sales'),
                DB::raw('sum(sale_items.quantity) as units'),
                DB::raw('sum(sale_items.quantity * sale_items.unit_price) as takings'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'outlet_id' => (int) $row->outlet_id,
                'name' => (string) $row->name,
                'sales' => (int) $row->sales,
                'units' => (int) $row->units,
                'takings' => self::centavos($row->takings),
            ]);
    }

    /**
     * How many of each size sold, keyed by nothing but the size itself.
     *
     * The forecast reads demand per size and joins it to the catalogue in PHP,
     * so this stays a bare count against the id rather than repeating the
     * product joins the reports need.
     *
     * @return Collection<int, array{product_variant_id: int, units: int}>
     */
    public static function unitsSoldByVariant(string $from, string $to): Collection
    {
        return self::completedLines($from, $to)
            ->groupBy('sale_items.product_variant_id')
            ->select([
                'sale_items.product_variant_id as product_variant_id',
                DB::raw('sum(sale_items.quantity) as units'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'units' => (int) $row->units,
            ]);
    }

    /**
     * How many completed sales were rung up over a stretch of days.
     */
    public static function countCompleted(string $from, string $to, ?int $outletId = null): int
    {
        return static::query()
            ->completed()
            ->whereDate('sold_at', '>=', $from)
            ->whereDate('sold_at', '<=', $to)
            ->when($outletId !== null, fn (Builder $query) => $query->where('outlet_id', $outletId))
            ->count();
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
