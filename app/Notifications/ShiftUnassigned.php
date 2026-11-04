<?php

namespace App\Notifications;

use App\Models\Shift;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * The employee has been taken off a shift.
 */
class ShiftUnassigned extends Notification
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
     * Points at the employee's own roster, where the shift is now missing, or
     * at the shift itself for an Administrator.
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        $url = $notifiable instanceof User && $notifiable->can('view-own-schedule')
            ? route('employee.schedule')
            : route('employee.workforce.shifts.show', $this->shift);

        return [
            'title' => __('You are off :shift', ['shift' => $this->shift->name]),
            'body' => __(':start – :end', [
                'start' => $this->shift->starts_at->format('D d M, g:ia'),
                'end' => $this->shift->ends_at->format('g:ia'),
            ]),
            'url' => $url,
            'icon' => 'calendar',
        ];
    }

    public function toSms(object $notifiable): string
    {
        return __('Purple Yam: You have been taken off :shift.', [
            'shift' => $this->shift->textLine(),
        ]);
    }
}
