<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Conversation $conversation;

    public string $body = '';

    /**
     * Open the thread, if it is one of the signed-in employee's.
     *
     * Membership is the authorization here — there is no gate for internal
     * chat, because every role has it; what matters is that nobody reads a
     * conversation they are not in.
     */
    public function mount(Conversation $conversation): void
    {
        abort_unless($conversation->includes(Auth::user()), 403);

        $this->conversation = $conversation->load('members');

        $this->markRead();
    }

    /**
     * Name the tab after the thread.
     */
    public function rendering(View $view): void
    {
        $view->title($this->conversation->subject);
    }

    /**
     * What has been said, oldest first.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Say something.
     */
    public function send(): void
    {
        abort_unless($this->conversation->includes(Auth::user()), 403);

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $this->conversation->postMessage(Auth::user(), $validated['body']);

        $this->reset('body');

        unset($this->messages);
    }

    /**
     * Pick up anything said since the page was drawn.
     *
     * Polling rather than broadcasting: a bakery's internal chat does not
     * justify a websocket server, and the spec warns against building a social
     * network out of this.
     */
    public function refreshThread(): void
    {
        unset($this->messages);

        $this->markRead();
    }

    /**
     * Mark everything in the thread as seen by the signed-in employee.
     */
    protected function markRead(): void
    {
        $this->conversation->markReadFor(Auth::user());
    }
}; ?>

<section class="w-full max-w-3xl" wire:poll.15s="refreshThread">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $conversation->subject }}</flux:heading>
            <flux:text class="mt-2">{{ $conversation->members->pluck('name')->join(', ') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.messages.index')" wire:navigate>
            {{ __('Back to messages') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="flex flex-col gap-4">
        @foreach ($this->messages as $message)
            @php($mine = $message->user_id === auth()->id())

            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}" wire:key="message-{{ $message->id }}">
                <div class="max-w-lg rounded-lg px-4 py-3 {{ $mine ? 'bg-purple-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800' }}">
                    @unless ($mine)
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $message->sender->name }}</p>
                    @endunless

                    <p class="mt-1 whitespace-pre-line">{{ $message->body }}</p>

                    <p class="mt-1 text-xs {{ $mine ? 'text-purple-200' : 'text-zinc-400' }}">
                        {{ $message->created_at->format('d M, g:ia') }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    <form wire:submit="send" class="mt-6 flex flex-col gap-3">
        <flux:textarea wire:model="body" :label="__('Message')" rows="3" required />

        <flux:button variant="primary" type="submit" class="self-start">{{ __('Send') }}</flux:button>
    </form>
</section>
