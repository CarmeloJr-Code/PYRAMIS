<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * Texts the customer that their order can be collected.
 *
 * Customers have no accounts (BR-001), so this goes to the number on the order
 * through an on-demand route and nothing about them is kept. The text carries
 * no link: UniSMS refuses links from a default sender ID, so the reference is
 * what the customer shows at the counter.
 */
class OrderReadyForPickup extends Notification
{
    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return __('Purple Yam Malaybalay: Your order :reference is ready for pickup at :outlet. Show this reference when you collect it.', [
            'reference' => $this->order->reference,
            'outlet' => $this->order->outlet->name,
        ]);
    }
}
