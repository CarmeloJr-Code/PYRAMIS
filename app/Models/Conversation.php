<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A thread of internal messages between employees.
 *
 * Text only, by BR-010 and FR-09 — no attachments, no external platforms, and
 * nothing here that would make room for either.
 *
 * @property int $id
 * @property string $subject
 * @property int $started_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['subject', 'started_by'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * The employee who opened it.
     *
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Who is in it.
     *
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * The employees themselves.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_message_id')
            ->withTimestamps();
    }

    /**
     * What has been said.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Limit the query to conversations the given employee is in.
     *
     * The only way conversations are ever listed: nobody sees a thread they are
     * not part of.
     *
     * @param  Builder<Conversation>  $query
     */
    #[Scope]
    protected function withMember(Builder $query, User $user): void
    {
        $query->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id));
    }

    /**
     * Whether the given employee is in this conversation.
     */
    public function includes(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists();
    }

    /**
     * Add a message to the thread.
     *
     * The rule that only participants may speak lives with the data rather than
     * in whichever screen is posting, so a crafted request cannot put words in
     * a thread its sender was never in.
     *
     * @throws RuntimeException when the sender is not a participant
     */
    public function postMessage(User $sender, string $body): Message
    {
        if (! $this->includes($sender)) {
            throw new RuntimeException('Only the people in a conversation can post to it.');
        }

        $message = $this->messages()->create([
            'user_id' => $sender->id,
            'body' => trim($body),
        ]);

        // Whoever just spoke has read everything up to their own message.
        $this->markReadFor($sender);

        return $message;
    }

    /**
     * Record that the given employee has read the thread as it stands.
     *
     * The cached relation is dropped afterwards: the update goes through the
     * query builder, so anything already loaded would keep reporting the old
     * read mark and count messages as unread that plainly are not.
     */
    public function markReadFor(User $user): void
    {
        $latestId = $this->messages()->max('id');

        $this->participants()
            ->where('user_id', $user->id)
            ->update(['last_read_message_id' => $latestId]);

        $this->unsetRelation('participants');
    }

    /**
     * How many messages the given employee has not seen.
     */
    public function unreadCountFor(User $user): int
    {
        $participant = $this->participants->firstWhere('user_id', $user->id)
            ?? $this->participants()->where('user_id', $user->id)->first();

        if ($participant === null) {
            return 0;
        }

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when(
                $participant->last_read_message_id !== null,
                fn (Builder $messages) => $messages->where('id', '>', $participant->last_read_message_id),
            )
            ->count();
    }
}
