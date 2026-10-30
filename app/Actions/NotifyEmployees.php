<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Sends a workspace notification to every employee whose role covers it.
 *
 * Recipients are chosen by ability rather than by a list of roles, so who hears
 * about an order or a restock is decided by the same gates that decide who may
 * open it — a role given or taken away a screen gains or loses its
 * notifications with it.
 */
final class NotifyEmployees
{
    /**
     * Notify every active employee who holds the ability, except the one who
     * caused the event: nobody needs telling what they have just done.
     */
    public function handle(Notification $notification, string $ability, ?User $except = null): void
    {
        $recipients = User::query()
            ->active()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->filter(fn (User $user): bool => $user->can($ability));

        Notifier::send($recipients, $notification);
    }
}
