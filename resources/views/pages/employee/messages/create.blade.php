<?php

use App\Actions\StartConversation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New conversation')] class extends Component {
    public string $subject = '';

    public string $body = '';

    /**
     * Who else is being brought in.
     *
     * @var array<int, int>
     */
    public array $participantIds = [];

    /**
     * The colleagues who can be messaged.
     *
     * Everyone still working here except the sender, who is always in their own
     * thread. Closed accounts are not people to write to.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function colleagues(): Collection
    {
        return User::query()
            ->active()
            ->whereKeyNot(Auth::id())
            ->orderBy('name')
            ->get();
    }

    /**
     * Open the conversation.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'participantIds' => ['required', 'array', 'min:1'],
            'participantIds.*' => ['integer', 'exists:users,id'],
            // Text, and only text (BR-010).
            'body' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $conversation = app(StartConversation::class)->handle(
                Auth::user(),
                $validated['subject'],
                array_map('intval', $validated['participantIds']),
                $validated['body'],
            );
        } catch (\RuntimeException $exception) {
            $this->addError('participantIds', $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Conversation started.'));

        $this->redirectRoute('employee.messages.show', $conversation, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('New conversation') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Text only — the system carries no photos, voice or video.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->colleagues->isEmpty())
        <flux:callout icon="users">
            <flux:callout.heading>{{ __('Nobody to message') }}</flux:callout.heading>
            <flux:callout.text>{{ __('There are no other active employees yet.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="subject" :label="__('What it is about')" required autofocus />

            <div class="flex flex-col gap-2">
                <flux:heading size="sm">{{ __('Who is in it') }}</flux:heading>

                @error('participantIds')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    @foreach ($this->colleagues as $colleague)
                        <flux:checkbox
                            wire:model="participantIds"
                            :value="$colleague->id"
                            :label="$colleague->name.' · '.$colleague->role->label()"
                            wire:key="colleague-{{ $colleague->id }}"
                        />
                    @endforeach
                </div>
            </div>

            <flux:textarea wire:model="body" :label="__('First message')" rows="4" required />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Start conversation') }}</flux:button>
                <flux:button variant="ghost" :href="route('employee.messages.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
