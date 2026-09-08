<?php

namespace App\Actions;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens a conversation with its first message.
 *
 * A thread with nobody in it, or with nothing said, is not worth having, so
 * both happen together or neither does.
 */
class StartConversation
{
    /**
     * Start the conversation.
     *
     * @param  list<int>  $participantIds  Everyone else being brought in.
     *
     * @throws RuntimeException when nobody was named, or one of them is not an
     *                          employee who can be written to
     */
    public function handle(User $starter, string $subject, array $participantIds, string $firstMessage): Conversation
    {
        // The starter is always in their own thread, and cannot be counted
        // twice by naming themselves.
        $participantIds = array_values(array_unique(array_diff($participantIds, [$starter->id])));

        if ($participantIds === []) {
            throw new RuntimeException('A conversation needs somebody else in it.');
        }

        $recipients = User::query()
            ->active()
            ->whereKey($participantIds)
            ->get();

        if ($recipients->count() !== count($participantIds)) {
            throw new RuntimeException('Somebody on this conversation is no longer an active employee.');
        }

        return DB::transaction(function () use ($starter, $subject, $recipients, $firstMessage): Conversation {
            $conversation = Conversation::create([
                'subject' => trim($subject),
                'started_by' => $starter->id,
            ]);

            $conversation->participants()->create(['user_id' => $starter->id]);

            foreach ($recipients as $recipient) {
                $conversation->participants()->create(['user_id' => $recipient->id]);
            }

            $conversation->postMessage($starter, $firstMessage);

            return $conversation;
        });
    }
}
