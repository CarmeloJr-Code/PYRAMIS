<?php

namespace App\Notifications;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * The employee has been put on a shift.
 */
class ShiftAssigned extends Notification
{
    public function __construct(public Shift $shift) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Points at the employee's own roster where they have one, and at the shift
     * itself for an Administrator, who reads shifts through workforce.
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        $url = $notifiable instanceof User && $notifiable->can('view-own-schedule')
            ? route('employee.schedule')
            : route('employee.workforce.shifts.show', $this->shift);

        return [
            'title' => __('You are on :shift', ['shift' => $this->shift->name]),
            'body' => __(':start – :end', [
                'start' => $this->shift->starts_at->format('D d M, g:ia'),
                'end' => $this->shift->ends_at->format('g:ia'),
            ]),
            'url' => $url,
            'icon' => 'calendar',
        ];
    }
}
