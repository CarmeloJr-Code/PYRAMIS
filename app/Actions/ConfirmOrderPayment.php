<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The cashier's check that a pre-order's GCash payment arrived in full — the
 * step that moves it from Pending to Confirmed.
 */
class ConfirmOrderPayment
{
    /**
     * Record what arrived and confirm the order, atomically.
     *
     * Only the exact total confirms. Short is not paid, and over is money the
     * shop would owe back, so either way the order stays Pending for the
     * cashier to settle with the customer.
     *
     * @throws ValidationException when the amount does not match the total, or
     *                             the reference already paid for another order
     * @throws RuntimeException when the order is no longer waiting on payment
     */
    public function handle(Order $order, User $cashier, string $amount, string $gcashReference): OrderPayment
    {
        return DB::transaction(function () use ($order, $cashier, $amount, $gcashReference): OrderPayment {
            // Re-read inside the transaction and lock, so two cashiers checking
            // the same payment cannot both confirm it.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Pending) {
                throw new RuntimeException("Order {$order->reference} is not waiting on payment.");
            }

            $received = (int) round((float) $amount * 100);
            $due = $order->totalInCentavos();

            if ($received !== $due) {
                throw ValidationException::withMessages([
                    'amount' => __('₱:received received, but the order total is ₱:due — :difference by ₱:gap. The order stays Pending.', [
                        'received' => number_format($received / 100, 2),
                        'due' => number_format($due / 100, 2),
                        'difference' => $received < $due ? __('short') : __('over'),
                        'gap' => number_format(abs($due - $received) / 100, 2),
                    ]),
                ]);
            }

            // One GCash transfer pays for one order. A reference a cashier has
            // already accepted elsewhere is a receipt being shown twice — or a
            // typing slip, which the cashier would want caught just the same.
            $paidWith = OrderPayment::query()
                ->withoutReceipt()
                ->where('gcash_reference', $gcashReference)
                ->whereNotNull('verified_at')
                ->where('order_id', '!=', $order->id)
                ->with('order')
                ->first();

            if ($paidWith !== null) {
                throw ValidationException::withMessages([
                    'gcash_reference' => __('This GCash reference already paid for order :reference. The order stays Pending.', [
                        'reference' => $paidWith->order->reference,
                    ]),
                ]);
            }

            $payment = $order->payment()->updateOrCreate([], [
                'gcash_reference' => $gcashReference,
                'amount' => number_format($received / 100, 2, '.', ''),
                'verified_by' => $cashier->id,
                'verified_at' => now(),
            ]);

            $order->transitionTo(OrderStatus::Confirmed);

            return $payment;
        });
    }
}
