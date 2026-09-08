<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\ProductStockMovementType;
use App\Enums\SaleStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a collected pre-order into a sale — the "Customer Order → Confirmed
 * Order → Sale Transaction" step of the Phase 4 spec.
 */
class RecordSaleForOrder
{
    /**
     * Complete the order and record the money taken for it, atomically.
     *
     * The sale snapshots its own lines rather than reading through to the
     * order. A sale is the record of what money changed hands, so it has to
     * stay meaningful on its own — and the two can legitimately diverge once
     * walk-in sales and discounts exist.
     *
     * @throws RuntimeException when the order cannot be completed, or has
     *                          already been billed
     */
    public function handle(Order $order, User $cashier): Sale
    {
        return DB::transaction(function () use ($order, $cashier): Sale {
            // Re-read inside the transaction and lock, so two cashiers hitting
            // Complete at the same moment cannot both raise a sale.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->sale()->exists()) {
                throw new RuntimeException("Order {$order->reference} has already been billed.");
            }

            // transitionTo enforces the workflow — a Pending order cannot jump
            // straight to Completed just because a sale was requested.
            $order->transitionTo(OrderStatus::Completed);

            $sale = Sale::create([
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'recorded_by' => $cashier->id,
                'status' => SaleStatus::Completed,
                'sold_at' => now(),
            ]);

            $order->load('items.productVariant');

            $movements = app(RecordProductStockMovement::class);

            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                $sale->items()->create([
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ]);

                // The goods leave the outlet the customer collected from. A
                // sale may take that shelf negative — the cake went out of the
                // door whatever the count believed.
                $movements->handle(
                    $item->productVariant,
                    $order->outlet,
                    $cashier,
                    ProductStockMovementType::Sale,
                    -$item->quantity,
                    null,
                    null,
                    null,
                    $sale,
                );
            }

            return $sale;
        });
    }
}
