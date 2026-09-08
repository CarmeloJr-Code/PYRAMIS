<?php

namespace App\Actions;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts an employee on a shift, refusing a clash.
 *
 * The only thing that writes an assignment, so the conflict rule the Phase 8
 * spec asks for lives here rather than in whichever screen is calling it.
 */
class AssignShift
{
    /**
     * Assign the employee, refusing anything already on their roster that runs
     * across it.
     *
     * @throws RuntimeException when the employee is already working then
     */
    public function handle(Shift $shift, User $employee, User $administrator): ShiftAssignment
    {
        return DB::transaction(function () use ($shift, $employee, $administrator): ShiftAssignment {
            // Lock the employee's row rather than the shift's: the clash is a
            // fact about one person's day, and two administrators filling two
            // different shifts at once would otherwise both look before either
            // wrote.
            User::query()->lockForUpdate()->findOrFail($employee->id);

            $existing = $shift->assignments()->where('user_id', $employee->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $clash = Shift::query()
                ->overlapping($shift->starts_at, $shift->ends_at)
                ->whereKeyNot($shift->id)
                ->whereHas('assignments', fn ($query) => $query->where('user_id', $employee->id))
                ->orderBy('starts_at')
                ->first();

            if ($clash !== null) {
                throw new RuntimeException(sprintf(
                    '%s is already on %s, %s–%s.',
                    $employee->name,
                    $clash->name,
                    $clash->starts_at->format('d M, g:ia'),
                    $clash->ends_at->format('g:ia'),
                ));
            }

            return $shift->assignments()->create([
                'user_id' => $employee->id,
                'assigned_by' => $administrator->id,
            ]);
        });
    }
}
