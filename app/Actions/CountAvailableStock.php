<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductStockMovement;
use Illuminate\Support\Collection;

/**
 * What a customer can still claim of each size, at each pickup outlet.
 *
 * The shelf at an outlet is the sum of its finished-goods movements, but an
 * open pre-order does not take its goods off that shelf until it is collected
 * and billed (RecordSaleForOrder). So what is left to promise is the shelf
 * less what open orders for that outlet already claim — floored at zero, since
 * a shelf a sale took negative still has nothing on it.
 *
 * Two grouped queries whatever the size of the menu, so the storefront's cost
 * does not grow with the catalogue or the order book. Nothing here writes.
 */
final class CountAvailableStock
{
    /**
     * Units available per size, then per outlet.
     *
     * Every requested pair is present, zero when nothing is there, so a view
     * never has to tell "none" apart from "not counted".
     *
     * @param  Collection<int, int>  $variantIds
     * @param  Collection<int, int>  $outletIds
     * @return array<int, array<int, int>>
     */
    public function handle(Collection $variantIds, Collection $outletIds): array
    {
        if ($variantIds->isEmpty() || $outletIds->isEmpty()) {
            return [];
        }

        $shelf = ProductStockMovement::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('outlet_id', $outletIds)
            ->groupBy('product_variant_id', 'outlet_id')
            ->selectRaw('product_variant_id, outlet_id, sum(quantity) as units')
            ->toBase()
            ->get();

        $claimed = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', Order::query()->open()->whereIn('outlet_id', $outletIds)->select('id'))
            ->whereIn('order_items.product_variant_id', $variantIds)
            ->groupBy('order_items.product_variant_id', 'orders.outlet_id')
            ->selectRaw('order_items.product_variant_id, orders.outlet_id, sum(order_items.quantity) as units')
            ->toBase()
            ->get();

        $available = [];

        foreach ($variantIds as $variantId) {
            foreach ($outletIds as $outletId) {
                $available[$variantId][$outletId] = 0;
            }
        }

        foreach ($shelf as $row) {
            $available[$row->product_variant_id][$row->outlet_id] += (int) $row->units;
        }

        foreach ($claimed as $row) {
            $available[$row->product_variant_id][$row->outlet_id] -= (int) $row->units;
        }

        return array_map(
            fn (array $outlets): array => array_map(fn (int $units): int => max(0, $units), $outlets),
            $available,
        );
    }
}
