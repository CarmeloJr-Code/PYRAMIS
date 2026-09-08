<?php

namespace App\Actions;

use App\Enums\ProductStockMovementType;
use App\Enums\RestockStatus;
use App\Models\Outlet;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sends a prepared restock to its outlet.
 *
 * The step the Phase 7 exit criterion turns on: finished goods leave the main
 * branch and arrive in the outlet's inventory, where they can be sold. Both
 * halves are written in one transaction, so stock is never in two places at
 * once and never in neither.
 */
class DeliverRestock
{
    /**
     * Deliver the restock, refusing anything that would corrupt either shelf.
     *
     * @throws RuntimeException when the restock is not ready to travel, or the
     *                          main branch cannot cover what was prepared
     */
    public function handle(Restock $restock, User $employee): Restock
    {
        return DB::transaction(function () use ($restock, $employee): Restock {
            // Re-read inside the transaction and lock, so two people pressing
            // Deliver at the same moment cannot both move the same goods.
            $restock = Restock::query()->lockForUpdate()->findOrFail($restock->id);

            $restock->load('items.productVariant');

            if ($restock->status !== RestockStatus::Preparing) {
                throw new RuntimeException(
                    "A {$restock->status->label()} restock cannot be delivered.",
                );
            }

            if (! $restock->isPrepared()) {
                throw new RuntimeException('Every line needs a prepared quantity before this can go out.');
            }

            $travelling = $restock->items->filter(
                fn (RestockItem $item): bool => $item->travellingQuantity() > 0,
            );

            if ($travelling->isEmpty()) {
                throw new RuntimeException('Nothing was prepared, so there is nothing to deliver.');
            }

            $mainBranch = Outlet::mainBranch();
            $movements = app(RecordProductStockMovement::class);

            foreach ($travelling as $item) {
                /** @var RestockItem $item */
                $quantity = $item->travellingQuantity();

                // Off the main branch's shelf first: if it cannot cover the
                // line, the whole delivery rolls back rather than crediting an
                // outlet with goods that never left.
                $movements->handle(
                    $item->productVariant,
                    $mainBranch,
                    $employee,
                    ProductStockMovementType::Transfer,
                    -$quantity,
                    null,
                    null,
                    $restock,
                );

                $movements->handle(
                    $item->productVariant,
                    $restock->outlet,
                    $employee,
                    ProductStockMovementType::Transfer,
                    $quantity,
                    null,
                    null,
                    $restock,
                );
            }

            $restock->transitionTo(RestockStatus::Delivered);

            // Set directly rather than through fill(): who moved the goods is
            // part of the record, not something a form may claim.
            $restock->delivered_by = $employee->id;
            $restock->save();

            return $restock;
        });
    }
}
