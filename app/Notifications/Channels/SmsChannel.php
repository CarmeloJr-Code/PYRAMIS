<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Texts a notification through UniSMS.
 *
 * The one place that talks to the gateway. A notification opts in by naming
 * this class in via() and returning its text from toSms(); the recipient comes
 * from routeNotificationFor('sms'), so an employee's number on their record and
 * a customer's number on an on-demand route arrive the same way.
 *
 * Nothing runs a queue worker, so the text goes out during the request. A text
 * only tells someone about a change already made, so a failure is reported and
 * swallowed — it never undoes or blocks the shift or order behind it. With no
 * key configured the text is written to the log instead.
 */
class SmsChannel
{
    /**
     * Send the notification's text to the notifiable's mobile number.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'routeNotificationFor') || ! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($phone) || $phone === '') {
            return;
        }

        $recipient = $this->international($phone);
        $content = (string) $notification->toSms($notifiable);
        $key = config('services.unisms.key');

        if (blank($key)) {
            Log::info('SMS not sent: no UniSMS key is configured.', [
                'recipient' => $this->masked($recipient),
                'content' => $content,
            ]);

            return;
        }

        try {
            Http::withBasicAuth($key, '')
                ->asJson()
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->post(config('services.unisms.url'), [
                    'recipient' => $recipient,
                    'content' => $content,
                    'sender_id' => config('services.unisms.sender_id'),
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            report(new RuntimeException(
                sprintf('SMS to %s failed: %s', $this->masked($recipient), $exception->getMessage()),
                previous: $exception,
            ));
        }
    }

    /**
     * Numbers are stored the way customers type them, 09XXXXXXXXX; the gateway
     * is sent the +63 form.
     */
    protected function international(string $phone): string
    {
        return str_starts_with($phone, '09') ? '+63'.substr($phone, 1) : $phone;
    }

    /**
     * Only the last four digits go into logs and error reports.
     */
    protected function masked(string $recipient): string
    {
        return str_repeat('*', max(0, strlen($recipient) - 4)).substr($recipient, -4);
    }
}
