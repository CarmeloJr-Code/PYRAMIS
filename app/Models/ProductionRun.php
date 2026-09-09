<?php

namespace App\Models;

use App\Concerns\GeneratesReference;
use Carbon\CarbonImmutable;
use Database\Factories\ProductionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One baking run: what was made, how much of it, and what it took.
 *
 * The run is the record of finished-product output. What it consumed lives in
 * the inventory ledger pointing back at it, so consumption is never recomputed
 * from the recipe — editing a recipe afterwards cannot rewrite what a past bake
 * actually used.
 *
 * @property int $id
 * @property string $reference
 * @property int $product_variant_id
 * @property int $recipe_id
 * @property int $quantity
 * @property int $recorded_by
 * @property string|null $notes
 * @property CarbonImmutable $produced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'recipe_id', 'quantity', 'recorded_by', 'notes', 'produced_at'])]
class ProductionRun extends Model
{
    /** @use HasFactory<ProductionRunFactory> */
    use GeneratesReference, HasFactory;

    /**
     * Production runs get their own prefix so a reference is self-identifying.
     */
    protected static function referencePrefix(): string
    {
        return 'PD-';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'produced_at' => 'datetime',
        ];
    }

    /**
     * The size that was made.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The recipe it was costed against.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * The employee who logged it.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * What it took out of the stockroom.
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * How many units came out of the oven over a stretch of days.
     */
    public static function unitsProduced(string $from, string $to): int
    {
        return (int) static::query()->producedBetween($from, $to)->sum('quantity');
    }

    /**
     * How many runs were logged over a stretch of days.
     */
    public static function runsLogged(string $from, string $to): int
    {
        return static::query()->producedBetween($from, $to)->count();
    }

    /**
     * Output day by day — the production history, one row per baking day.
     *
     * @return Collection<int, array{day: string, runs: int, units: int}>
     */
    public static function dailyOutput(string $from, string $to): Collection
    {
        // date() rather than a cast, for the same reason as the sales series:
        // both engines have it and both hand back a plain day string.
        return DB::table('production_runs')
            ->whereDate('produced_at', '>=', $from)
            ->whereDate('produced_at', '<=', $to)
            ->groupBy(DB::raw('date(produced_at)'))
            ->orderBy('day')
            ->select([
                DB::raw('date(produced_at) as day'),
                DB::raw('count(*) as runs'),
                DB::raw('sum(quantity) as units'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                'runs' => (int) $row->runs,
                'units' => (int) $row->units,
            ]);
    }

    /**
     * What came out of the oven, by size, most produced first.
     *
     * @return Collection<int, array{product: string, size: string, runs: int, units: int}>
     */
    public static function outputByVariant(string $from, string $to): Collection
    {
        return DB::table('production_runs')
            ->join('product_variants', 'product_variants.id', '=', 'production_runs.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereDate('produced_at', '>=', $from)
            ->whereDate('produced_at', '<=', $to)
            ->groupBy('product_variants.id', 'products.id')
            ->orderByDesc('units')
            ->select([
                'products.name as product',
                'product_variants.name as size',
                DB::raw('count(*) as runs'),
                DB::raw('sum(production_runs.quantity) as units'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'product' => (string) $row->product,
                'size' => (string) $row->size,
                'runs' => (int) $row->runs,
                'units' => (int) $row->units,
            ]);
    }

    /**
     * Limit the query to runs over a stretch of days, both ends included.
     *
     * @param  Builder<ProductionRun>  $query
     */
    #[Scope]
    protected function producedBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('produced_at', '>=', $from)->whereDate('produced_at', '<=', $to);
    }

    /**
     * Limit the query to runs of a given day.
     *
     * @param  Builder<ProductionRun>  $query
     */
    #[Scope]
    protected function producedOn(Builder $query, string $date): void
    {
        $query->whereDate('produced_at', $date);
    }
}
