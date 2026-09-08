<?php

namespace App\Actions;

use App\Enums\ProductStockMovementType;
use App\Models\Outlet;
use App\Models\ProductionRun;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes one entry to a location's finished-goods ledger.
 *
 * The only thing in the application that changes finished stock — production
 * credits the main branch through here, and restocking will move it on through
 * here too, so the invariants live in the action rather than in whichever
 * screen happens to be calling it.
 */
class RecordProductStockMovement
{
    /**
     * Record the movement, refusing anything that would corrupt the ledger.
     *
     * @param  int  $quantity  Signed whole units: positive adds stock, negative removes it.
     * @param  ProductionRun|null  $productionRun  The bake this came out of, when it came from one.
     *
     * @throws RuntimeException when the movement is not a legal one
     */
    public function handle(
        ProductVariant $productVariant,
        Outlet $outlet,
        User $employee,
        ProductStockMovementType $type,
        int $quantity,
        ?string $note = null,
        ?ProductionRun $productionRun = null,
    ): ProductStockMovement {
        $note = $note === null ? null : trim($note);

        if ($quantity === 0) {
            throw new RuntimeException('A movement has to change the quantity.');
        }

        if ($quantity < 0 && ! $type->allowsDecrease()) {
            throw new RuntimeException("A {$type->label()} movement cannot reduce stock.");
        }

        if ($quantity > 0 && ! $type->allowsIncrease()) {
            throw new RuntimeException("A {$type->label()} movement cannot add stock.");
        }

        if ($type->requiresNote() && blank($note)) {
            throw new RuntimeException("A {$type->label()} needs a reason.");
        }

        return DB::transaction(function () use ($productVariant, $outlet, $employee, $type, $quantity, $note, $productionRun): ProductStockMovement {
            // Lock the location rather than the ledger: two people drawing the
            // same shelf down at once would otherwise both read the old balance
            // and between them take more than is there.
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->id);

            $onHand = $productVariant->stockAt($outlet);

            if ($onHand + $quantity < 0) {
                throw new RuntimeException(
                    "Only {$onHand} of {$productVariant->name} at {$outlet->name}.",
                );
            }

            return ProductStockMovement::create([
                'product_variant_id' => $productVariant->id,
                'outlet_id' => $outlet->id,
                'type' => $type,
                'quantity' => $quantity,
                'production_run_id' => $productionRun?->id,
                'recorded_by' => $employee->id,
                'note' => $note ?: null,
                'occurred_at' => now(),
            ]);
        });
    }
}
