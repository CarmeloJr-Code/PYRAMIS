<?php

namespace App\Notifications;

use App\Models\Shift;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * A shift the employee is on now starts, ends or is worked somewhere else.
 */
class ShiftChanged extends Notification
{
    public function __construct(public Shift $shift) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    /**
     * Points where ShiftAssigned does: the employee's own roster, or the shift
     * itself for an Administrator.
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        $url = $notifiable instanceof User && $notifiable->can('view-own-schedule')
            ? route('employee.schedule')
            : route('employee.workforce.shifts.show', $this->shift);

        return [
            'title' => __(':shift has changed', ['shift' => $this->shift->name]),
            'body' => __(':start – :end at :outlet', [
                'start' => $this->shift->starts_at->format('D d M, g:ia'),
                'end' => $this->shift->ends_at->format('g:ia'),
                'outlet' => $this->shift->outlet->name,
            ]),
            'url' => $url,
            'icon' => 'calendar',
        ];
    }

    public function toSms(object $notifiable): string
    {
        return __('Purple Yam: Your shift has changed. It is now :shift.', [
            'shift' => $this->shift->textLine(),
        ]);
    }
}
