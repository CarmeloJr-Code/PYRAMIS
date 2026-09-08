<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShiftAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's place on one shift.
 *
 * @property int $id
 * @property int $shift_id
 * @property int $user_id
 * @property int $assigned_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['shift_id', 'user_id', 'assigned_by'])]
class ShiftAssignment extends Model
{
    /** @use HasFactory<ShiftAssignmentFactory> */
    use HasFactory;

    /**
     * The shift being worked.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * The employee working it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Administrator who put them on it.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
