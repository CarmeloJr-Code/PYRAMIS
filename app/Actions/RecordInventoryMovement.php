<?php

namespace App\Actions;

use App\Concerns\FormatsQuantities;
use App\Enums\InventoryMovementType;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\ProductionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes one entry to an ingredient's stock ledger.
 *
 * The only thing in the application that changes stock — the stockroom
 * screens and a production run both come through here, so the invariants live
 * in the action rather than in whichever screen happens to be calling it.
 */
class RecordInventoryMovement
{
    use FormatsQuantities;

    /**
     * Record the movement, refusing anything that would corrupt the ledger.
     *
     * @param  int  $quantityInThousandths  Signed: positive adds stock, negative removes it.
     * @param  ProductionRun|null  $productionRun  The bake this came out of, when it came from one.
     *
     * @throws RuntimeException when the movement is not a legal one
     */
    public function handle(
        Ingredient $ingredient,
        User $employee,
        InventoryMovementType $type,
        int $quantityInThousandths,
        ?string $note = null,
        ?ProductionRun $productionRun = null,
    ): InventoryMovement {
        $note = $note === null ? null : trim($note);

        if ($quantityInThousandths === 0) {
            throw new RuntimeException('A movement has to change the quantity.');
        }

        if ($quantityInThousandths < 0 && ! $type->allowsDecrease()) {
            throw new RuntimeException("A {$type->label()} movement cannot reduce stock.");
        }

        if ($quantityInThousandths > 0 && ! $type->allowsIncrease()) {
            throw new RuntimeException("A {$type->label()} movement cannot add stock.");
        }

        if ($type->requiresNote() && blank($note)) {
            throw new RuntimeException("A {$type->label()} needs a reason.");
        }

        return DB::transaction(function () use ($ingredient, $employee, $type, $quantityInThousandths, $note, $productionRun): InventoryMovement {
            // Lock the ingredient row rather than the ledger: two employees
            // drawing stock down at the same moment would otherwise both read
            // the old balance and between them take more than exists.
            $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);

            if ($ingredient->stockInThousandths() + $quantityInThousandths < 0) {
                // Named, because a batch of usage lines is recorded in one go
                // and the employee has to know which one is short.
                throw new RuntimeException(
                    "Only {$ingredient->stock()} {$ingredient->unit->abbreviation()} of {$ingredient->name} in stock.",
                );
            }

            return $ingredient->movements()->create([
                'type' => $type,
                'quantity' => static::quantityFromThousandths($quantityInThousandths),
                'recorded_by' => $employee->id,
                'production_run_id' => $productionRun?->id,
                'note' => $note ?: null,
                'occurred_at' => now(),
            ]);
        });
    }
}
