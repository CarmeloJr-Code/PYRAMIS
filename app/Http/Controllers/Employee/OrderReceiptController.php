<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Symfony\Component\HttpFoundation\Response;

/**
 * The screenshot of a customer's GCash receipt, for the cashier checking it.
 */
class OrderReceiptController extends Controller
{
    /**
     * Serve the stored screenshot inline.
     *
     * Workspace-only and behind manage-orders, like the order it belongs to: a
     * receipt carries the customer's name and number.
     */
    public function __invoke(Order $order): Response
    {
        $payment = $order->payment()->first();

        abort_unless($payment?->hasReceipt(), 404);

        $bytes = base64_decode((string) $payment->receipt, strict: true);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type' => $payment->receipt_mime,
            'Content-Disposition' => 'inline; filename="receipt-'.$order->reference.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
