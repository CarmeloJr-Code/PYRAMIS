<?php

namespace App\Models;

use App\Concerns\GeneratesReference;
use App\Enums\RestockStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RestockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finished goods travelling from the main branch to an outlet.
 *
 * The source is never stored: under BR-004 outlets receive from the main branch
 * and nowhere else, so there is only one place it can come from.
 *
 * @property int $id
 * @property string $reference
 * @property int $outlet_id
 * @property RestockStatus $status
 * @property CarbonImmutable $scheduled_for
 * @property int $requested_by
 * @property int|null $prepared_by
 * @property int|null $delivered_by
 * @property string|null $notes
 * @property CarbonImmutable|null $prepared_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['outlet_id', 'status', 'scheduled_for', 'requested_by', 'notes'])]
class Restock extends Model
{
    /** @use HasFactory<RestockFactory> */
    use GeneratesReference, HasFactory;

    /**
     * Restocks get their own prefix so a reference is self-identifying.
     */
    protected static function referencePrefix(): string
    {
        return 'RS-';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestockStatus::class,
            'scheduled_for' => 'date',
            'prepared_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Where it is going.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * What is going.
     *
     * @return HasMany<RestockItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RestockItem::class);
    }

    /**
     * The two shelves it moved between, once delivered.
     *
     * @return HasMany<ProductStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    /**
     * The Administrator who scheduled it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The Baker who set the goods aside.
     *
     * @return BelongsTo<User, $this>
     */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * Whoever sent it out.
     *
     * @return BelongsTo<User, $this>
     */
    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * Limit the query to restocks still being worked on.
     *
     * @param  Builder<Restock>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [RestockStatus::Requested, RestockStatus::Preparing]);
    }

    /**
     * Limit the query to restocks delivered over a stretch of days.
     *
     * Dated by delivery rather than by when it was asked for: a restock counts
     * towards an outlet's month in the month the goods actually arrived.
     *
     * @param  Builder<Restock>  $query
     */
    #[Scope]
    protected function deliveredBetween(Builder $query, string $from, string $to): void
    {
        $query->where('status', RestockStatus::Delivered)
            ->whereDate('delivered_at', '>=', $from)
            ->whereDate('delivered_at', '<=', $to);
    }

    /**
     * What each outlet actually received, keyed by outlet.
     *
     * Counted from what was set aside rather than what was asked for — the
     * prepared quantity is what left the main branch (BR-003).
     *
     * @return Collection<int, array{outlet_id: int, name: string, restocks: int, units: int}>
     */
    public static function deliveriesByOutlet(string $from, string $to): Collection
    {
        return DB::table('restocks')
            ->join('outlets', 'outlets.id', '=', 'restocks.outlet_id')
            ->leftJoin('restock_items', 'restock_items.restock_id', '=', 'restocks.id')
            ->where('restocks.status', RestockStatus::Delivered->value)
            ->whereDate('restocks.delivered_at', '>=', $from)
            ->whereDate('restocks.delivered_at', '<=', $to)
            ->groupBy('outlets.id')
            ->orderByDesc('units')
            ->select([
                'outlets.id as outlet_id',
                'outlets.name as name',
                DB::raw('count(distinct restocks.id) as restocks'),
                DB::raw('sum(restock_items.quantity_prepared) as units'),
            ])
            ->get()
            ->map(fn (object $row): array => [
                'outlet_id' => (int) $row->outlet_id,
                'name' => (string) $row->name,
                'restocks' => (int) $row->restocks,
                'units' => (int) ($row->units ?? 0),
            ]);
    }

    /**
     * How many units were asked for in total.
     */
    public function requestedUnits(): int
    {
        return (int) $this->items->sum('quantity_requested');
    }

    /**
     * How many units were actually set aside.
     */
    public function preparedUnits(): int
    {
        return (int) $this->items->sum(fn (RestockItem $item): int => $item->quantity_prepared ?? 0);
    }

    /**
     * Whether the Baker has said what was set aside.
     */
    public function isPrepared(): bool
    {
        return $this->items->every(fn (RestockItem $item): bool => $item->quantity_prepared !== null);
    }

    /**
     * Move the restock to a new status, refusing anything the workflow
     * disallows.
     *
     * The rules live here rather than in a component, so a crafted request
     * cannot walk a restock backwards or reopen a delivered one.
     *
     * @throws RuntimeException when the move is not allowed
     */
    public function transitionTo(RestockStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw new RuntimeException(
                "A {$this->status->label()} restock cannot become {$status->label()}.",
            );
        }

        $this->status = $status;

        // Set directly rather than through fill(): these stamps are deliberately
        // not mass-assignable, because only a transition may write them.
        match ($status) {
            RestockStatus::Preparing => $this->prepared_at = now(),
            RestockStatus::Delivered => $this->delivered_at = now(),
            RestockStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };

        $this->save();
    }
}
