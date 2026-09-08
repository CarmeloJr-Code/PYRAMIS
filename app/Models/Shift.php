<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stretch of work at a place, which employees are assigned to.
 *
 * The shift and the assignment are kept apart because a morning bake takes two
 * bakers and a counter takes one cashier: the same stretch of work can carry
 * more than one person, and people come and go from it without the shift
 * itself changing.
 *
 * @property int $id
 * @property int $outlet_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $name
 * @property int $created_by
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['outlet_id', 'starts_at', 'ends_at', 'name', 'created_by', 'notes'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Where it is worked.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * The Administrator who put it on the schedule.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who is on it.
     *
     * @return HasMany<ShiftAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * The employees themselves.
     *
     * @return BelongsToMany<User, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shift_assignments')->withTimestamps();
    }

    /**
     * Limit the query to shifts on a given day.
     *
     * A shift belongs to the day it starts on, so a night shift running past
     * midnight stays on the roster it was planned into.
     *
     * @param  Builder<Shift>  $query
     */
    #[Scope]
    protected function startingOn(Builder $query, string $date): void
    {
        $query->whereDate('starts_at', $date);
    }

    /**
     * Limit the query to shifts overlapping the given stretch of time.
     *
     * Touching at the edges is not an overlap: a shift ending at 17:00 and one
     * starting at 17:00 are a handover, not a conflict.
     *
     * @param  Builder<Shift>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }

    /**
     * How long the shift runs, for reading.
     */
    public function duration(): string
    {
        $minutes = $this->starts_at->diffInMinutes($this->ends_at);

        $hours = intdiv((int) $minutes, 60);
        $rest = (int) $minutes % 60;

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }
}
