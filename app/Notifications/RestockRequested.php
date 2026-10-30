<?php

namespace App\Notifications;

use App\Models\Restock;
use Illuminate\Notifications\Notification;

/**
 * A restock has been scheduled and waits for the main branch to prepare it.
 */
class RestockRequested extends Notification
{
    /**
     * @param  Restock  $restock  With its outlet loaded.
     */
    public function __construct(public Restock $restock) {}

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
            'title' => __('Restock :reference to prepare', ['reference' => $this->restock->reference]),
            'body' => __('For :outlet, scheduled :date.', [
                'outlet' => $this->restock->outlet->name,
                'date' => $this->restock->scheduled_for->format('d M'),
            ]),
            'url' => route('employee.restocks.show', $this->restock),
            'icon' => 'truck',
        ];
    }
}
