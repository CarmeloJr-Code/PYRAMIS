<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * A customer has placed a pre-order on the storefront.
 *
 * Every workspace notification is written to the database and nowhere else:
 * the bell is the only place one is read, and with no queue worker running
 * they are sent as the event is written rather than queued.
 */
class OrderPlaced extends Notification
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
            'title' => __('New pre-order :reference', ['reference' => $this->order->reference]),
            'body' => __(':customer · pickup :time. Waiting on GCash payment.', [
                'customer' => $this->order->customer_name,
                'time' => $this->order->pickup_at->format('d M, g:ia'),
            ]),
            'url' => route('employee.orders.show', $this->order),
            'icon' => 'shopping-bag',
        ];
    }
}
