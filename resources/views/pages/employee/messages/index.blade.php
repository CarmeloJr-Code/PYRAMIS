<?php

use App\Models\Conversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Messages')] class extends Component {
    /**
     * The conversations the signed-in employee is in, most recently spoken in
     * first.
     *
     * Scoped to the authenticated user, not to anything from the request: there
     * is no way to ask this screen for a thread you are not part of.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::query()
            ->withMember(Auth::user())
            ->with(['members', 'participants'])
            ->withMax('messages as last_message_at', 'created_at')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * How many messages are waiting across all of them.
     */
    #[Computed]
    public function unreadTotal(): int
    {
        return $this->conversations->sum(
            fn (Conversation $conversation): int => $conversation->unreadCountFor(Auth::user()),
        );
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Messages') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Internal text messages between employees.') }}</flux:text>
        </div>

        <flux:button :href="route('employee.messages.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New conversation') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->unreadTotal > 0)
        <div class="mb-6">
            <flux:badge color="purple">
                {{ trans_choice('{1} :count unread message|[2,*] :count unread messages', $this->unreadTotal, ['count' => $this->unreadTotal]) }}
            </flux:badge>
        </div>
    @endif

    @if ($this->conversations->isEmpty())
        <flux:callout icon="chat-bubble-left-right">
            <flux:callout.heading>{{ __('No conversations yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Start one to message a colleague about the day\'s work.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($this->conversations as $conversation)
                @php($unread = $conversation->unreadCountFor(auth()->user()))

                <a
                    href="{{ route('employee.messages.show', $conversation) }}"
                    class="block rounded-lg border border-zinc-200 p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    wire:navigate
                    wire:key="conversation-{{ $conversation->id }}"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium">
                            {{ $conversation->subject }}
                            @if ($unread > 0)
                                <flux:badge size="sm" color="purple" class="ms-2">{{ $unread }}</flux:badge>
                            @endif
                        </p>

                        @if ($conversation->last_message_at)
                            <span class="text-sm text-zinc-500">
                                {{ \Illuminate\Support\Carbon::parse($conversation->last_message_at)->diffForHumans() }}
                            </span>
                        @endif
                    </div>

                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $conversation->members->pluck('name')->join(', ') }}
                    </p>
                </a>
            @endforeach
        </div>
    @endif
</section>
