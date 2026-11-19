<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
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

    /**
     * What was ordered and where to collect it: each line as quantity and the
     * name the counter uses, then the outlet's name and street address.
     */
    public function toSms(object $notifiable): string
    {
        $this->order->loadMissing(['outlet', 'items.productVariant.product.category']);

        return __('Purple Yam Malaybalay: Your order :reference is ready for pickup. Items: :items. Pick it up at :outlet, :address. Show this reference when you collect it.', [
            'reference' => $this->order->reference,
            'items' => $this->order->items
                ->sortBy('id')
                ->map(fn (OrderItem $item): string => "{$item->quantity}x {$item->productVariant->menuName()}")
                ->implode(', '),
            'outlet' => $this->order->outlet->name,
            'address' => $this->order->outlet->address,
        ]);
    }
}
