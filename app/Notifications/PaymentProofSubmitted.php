<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * A customer has sent proof of their GCash payment, so the order can be checked
 * and confirmed.
 */
class PaymentProofSubmitted extends Notification
{
    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Payment to check on :reference', ['reference' => $this->order->reference]),
            'body' => __(':customer sent proof of their GCash payment.', [
                'customer' => $this->order->customer_name,
            ]),
            'url' => route('employee.orders.show', $this->order),
            'icon' => 'banknotes',
        ];
    }
}
