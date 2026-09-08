<?php

namespace App\Actions;

use App\Enums\InventoryMovementType;
use App\Models\Ingredient;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Logs a baking run and takes what it used out of the stockroom.
 *
 * The whole of the Phase 6 workflow in one step: a recipe says what the run
 * needs, the ledger loses exactly that, and the run records the finished
 * output — all in one transaction, so a bake that cannot be covered does not
 * half-happen.
 */
class RecordProductionRun
{
    /**
     * Record the run.
     *
     * @throws RuntimeException when the size has no recipe, or the stockroom
     *                          cannot cover what the run needs
     */
    public function handle(
        ProductVariant $productVariant,
        User $baker,
        int $quantity,
        ?string $notes = null,
    ): ProductionRun {
        $notes = $notes === null ? null : trim($notes);

        if ($quantity < 1) {
            throw new RuntimeException('A run has to produce at least one unit.');
        }

        return DB::transaction(function () use ($productVariant, $baker, $quantity, $notes): ProductionRun {
            /** @var Recipe|null $recipe */
            $recipe = Recipe::query()
                ->with('items')
                ->where('product_variant_id', $productVariant->id)
                ->first();

            if ($recipe === null || $recipe->items->isEmpty()) {
                throw new RuntimeException("{$productVariant->name} has no recipe to produce against.");
            }

            $requirements = $recipe->requirementsInThousandths($quantity);

            $run = ProductionRun::create([
                'product_variant_id' => $productVariant->id,
                'recipe_id' => $recipe->id,
                'quantity' => $quantity,
                'recorded_by' => $baker->id,
                'notes' => $notes ?: null,
                'produced_at' => now(),
            ]);

            $movements = app(RecordInventoryMovement::class);

            // Ordered so the same bake always locks ingredients in the same
            // sequence; two runs sharing ingredients then queue rather than
            // deadlock against each other.
            ksort($requirements);

            foreach ($requirements as $ingredientId => $required) {
                // Rounding can leave a line at nothing when a very small
                // quantity is scaled down. Skipping it is right: the ledger
                // refuses a movement of zero, and the run should not fail
                // because a pinch of something disappeared in the arithmetic.
                if ($required === 0) {
                    continue;
                }

                $movements->handle(
                    Ingredient::findOrFail($ingredientId),
                    $baker,
                    InventoryMovementType::Usage,
                    -$required,
                    null,
                    $run,
                );
            }

            return $run;
        });
    }
}
