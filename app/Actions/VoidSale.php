<?php

namespace App\Actions;

use App\Enums\ProductStockMovementType;
use App\Models\ProductStockMovement;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Voids a sale and puts what it took back on the shelf.
 *
 * A voided sale is one that should never have been rung up, so the goods never
 * left. The stock has to come back, or the outlet's count drifts by exactly the
 * mistakes it made.
 */
class VoidSale
{
    /**
     * Void the sale, crediting back whatever it actually took.
     */
    public function handle(Sale $sale, User $employee): Sale
    {
        return DB::transaction(function () use ($sale, $employee): Sale {
            // Re-read inside the transaction and lock, so two cashiers pressing
            // Void at the same moment cannot credit the same goods back twice.
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->isVoided()) {
                return $sale;
            }

            $movements = app(RecordProductStockMovement::class);

            // Reverse what this sale actually wrote, rather than recomputing
            // from its lines. A sale recorded before the shelf existed took
            // nothing, and must not hand back stock that never moved.
            $taken = $sale->stockMovements()
                ->where('quantity', '<', 0)
                ->with(['productVariant', 'outlet'])
                ->get();

            foreach ($taken as $movement) {
                /** @var ProductStockMovement $movement */
                $movements->handle(
                    $movement->productVariant,
                    $movement->outlet,
                    $employee,
                    ProductStockMovementType::Sale,
                    abs($movement->quantity),
                    __('Sale voided'),
                    null,
                    null,
                    $sale,
                );
            }

            $sale->void();

            return $sale;
        });
    }
}
