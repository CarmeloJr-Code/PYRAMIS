<?php

namespace App\Notifications;

use App\Models\Restock;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A restock has reached its outlet.
 */
class RestockDelivered extends Notification
{
    /**
     * @param  Restock  $restock  With its outlet loaded.
     */
    public function __construct(public Restock $restock, public User $deliveredBy) {}

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
            'title' => __('Restock :reference delivered', ['reference' => $this->restock->reference]),
            'body' => __(':name delivered it to :outlet.', [
                'name' => $this->deliveredBy->name,
                'outlet' => $this->restock->outlet->name,
            ]),
            'url' => route('employee.restocks.show', $this->restock),
            'icon' => 'check-circle',
        ];
    }
}
