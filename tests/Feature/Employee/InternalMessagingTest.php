<?php

namespace Tests\Feature\Employee;

use App\Actions\StartConversation;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class InternalMessagingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A conversation between the given employees, started by the first.
     *
     * @param  array<int, User>  $members
     */
    private function conversation(string $subject, array $members): Conversation
    {
        return Conversation::factory()
            ->withMembers($members)
            ->create([
                'subject' => $subject,
                'started_by' => $members[0]->id,
            ])
            ->fresh(['members', 'participants']);
    }

    public function test_every_role_can_reach_their_messages(): void
    {
        // The capability matrix gives internal chat to all three roles.
        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.messages.index'))
                ->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.messages.index'))->assertRedirect(route('login'));
    }

    public function test_an_employee_starts_a_conversation(): void
    {
        $cashier = User::factory()->cashier()->create();
        $baker = User::factory()->baker()->create(['name' => 'Rosa Baker']);

        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.create')
            ->set('subject', 'Ube cakes for Saturday')
            ->set('participantIds', [$baker->id])
            ->set('body', 'Can we have twelve ready by nine?')
            ->call('save')
            ->assertHasNoErrors();

        $conversation = Conversation::firstWhere('subject', 'Ube cakes for Saturday');

        $this->assertNotNull($conversation);
        $this->assertSame($cashier->id, $conversation->started_by);

        // Both of them are in it, the starter included without being named.
        $this->assertSame(2, $conversation->participants()->count());
        $this->assertTrue($conversation->includes($cashier));
        $this->assertTrue($conversation->includes($baker));

        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame('Can we have twelve ready by nine?', $conversation->messages()->first()->body);
    }

    public function test_a_conversation_needs_somebody_else_in_it(): void
    {
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.create')
            ->set('subject', 'Talking to myself')
            ->set('participantIds', [])
            ->set('body', 'Hello?')
            ->call('save')
            ->assertHasErrors('participantIds');

        $this->assertSame(0, Conversation::count());
    }

    public function test_naming_yourself_does_not_make_a_conversation_of_one(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs somebody else');

        app(StartConversation::class)->handle($cashier, 'Just me', [$cashier->id], 'Hello?');
    }

    public function test_a_closed_account_cannot_be_brought_into_a_conversation(): void
    {
        $cashier = User::factory()->cashier()->create();
        $gone = User::factory()->baker()->create(['is_active' => false]);

        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.create')
            ->set('subject', 'Old business')
            ->set('participantIds', [$gone->id])
            ->set('body', 'Are you there?')
            ->call('save')
            ->assertHasErrors('participantIds');

        $this->assertSame(0, Conversation::count());
    }

    public function test_a_subject_or_a_message_is_required(): void
    {
        $cashier = User::factory()->cashier()->create();
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.create')
            ->set('subject', '')
            ->set('participantIds', [$baker->id])
            ->set('body', '')
            ->call('save')
            ->assertHasErrors(['subject', 'body']);

        $this->assertSame(0, Conversation::count());
    }

    public function test_the_list_shows_only_conversations_you_are_in(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();
        $administrator = User::factory()->administrator()->create();

        $mine = $this->conversation('Morning bake', [$baker, $cashier]);
        $theirs = $this->conversation('Payroll', [$administrator, $cashier]);

        Livewire::actingAs($baker)
            ->test('pages::employee.messages.index')
            ->assertSee($mine->subject)
            ->assertDontSee($theirs->subject);
    }

    public function test_a_conversation_cannot_be_opened_by_somebody_who_is_not_in_it(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();
        $outsider = User::factory()->administrator()->create();

        $conversation = $this->conversation('Private', [$baker, $cashier]);

        $this->actingAs($outsider)
            ->get(route('employee.messages.show', $conversation))
            ->assertForbidden();
    }

    public function test_a_participant_reads_and_replies(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();

        $conversation = $this->conversation('Morning bake', [$baker, $cashier]);
        $conversation->postMessage($baker, 'Twelve ube cakes are in the oven.');

        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.show', ['conversation' => $conversation])
            ->assertSee('Twelve ube cakes are in the oven.')
            ->set('body', 'Perfect, thank you.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $cashier->id,
            'body' => 'Perfect, thank you.',
        ]);
    }

    public function test_an_outsider_cannot_post_however_the_request_is_made(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();
        $outsider = User::factory()->administrator()->create();

        $conversation = $this->conversation('Private', [$baker, $cashier]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the people in a conversation');

        $conversation->postMessage($outsider, 'Let me in.');
    }

    public function test_unread_counts_what_others_said_since_you_last_looked(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();

        $conversation = $this->conversation('Morning bake', [$baker, $cashier]);

        $conversation->postMessage($baker, 'One.');
        $conversation->postMessage($baker, 'Two.');

        // The baker's own words are never unread to the baker.
        $this->assertSame(0, $conversation->unreadCountFor($baker));
        $this->assertSame(2, $conversation->unreadCountFor($cashier));

        // Opening the thread clears it.
        Livewire::actingAs($cashier)
            ->test('pages::employee.messages.show', ['conversation' => $conversation]);

        $this->assertSame(0, $conversation->fresh()->unreadCountFor($cashier));
    }

    public function test_replying_marks_the_thread_read_for_the_sender(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();

        $conversation = $this->conversation('Morning bake', [$baker, $cashier]);

        $conversation->postMessage($baker, 'Anything else?');
        $conversation->postMessage($cashier, 'No, that is all.');

        $this->assertSame(0, $conversation->unreadCountFor($cashier));
        $this->assertSame(1, $conversation->unreadCountFor($baker));
    }

    public function test_the_thread_shows_messages_oldest_first(): void
    {
        $baker = User::factory()->baker()->create();
        $cashier = User::factory()->cashier()->create();

        $conversation = $this->conversation('Morning bake', [$baker, $cashier]);

        Message::factory()->for($conversation)->saying('First thing')
            ->create(['user_id' => $baker->id, 'created_at' => now()->subHour()]);
        Message::factory()->for($conversation)->saying('Second thing')
            ->create(['user_id' => $cashier->id, 'created_at' => now()]);

        $messages = Livewire::actingAs($baker)
            ->test('pages::employee.messages.show', ['conversation' => $conversation])
            ->instance()
            ->messages();

        $this->assertSame('First thing', $messages->first()->body);
        $this->assertSame('Second thing', $messages->last()->body);
    }
}
