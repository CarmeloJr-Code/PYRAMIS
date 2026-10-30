<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The bell at the top of the workspace: what has happened that the signed-in
 * employee should know about.
 *
 * Every lookup goes through the employee's own notifications relation, so one
 * employee can neither read nor clear another's — whatever id a crafted request
 * sends.
 */
new class extends Component {
    /**
     * How many of the latest notifications the menu lists.
     */
    private const SHOWN = 10;

    /**
     * The latest notifications, newest first, read and unread alike.
     *
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return Auth::user()->notifications()->limit(self::SHOWN)->get();
    }

    /**
     * Everything not yet read, including what has fallen off the list.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * Mark the notification read and go to what it is about.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        $this->redirect($notification->data['url'], navigate: true);
    }

    /**
     * Mark everything read.
     */
    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->notifications, $this->unreadCount);
    }
}; ?>

{{-- Polled, so something that happens while a page sits open still reaches the bell. --}}
<div wire:poll.30s>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" square class="relative" :aria-label="__('Notifications')" data-test="notification-bell">
            <flux:icon.bell variant="outline" class="size-5" />

            @if ($this->unreadCount > 0)
                <span class="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white" data-test="notification-count">
                    {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
                </span>
            @endif
        </flux:button>

        <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between px-2 py-1.5">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>

                @if ($this->unreadCount > 0)
                    <flux:button size="xs" variant="ghost" wire:click="markAllAsRead">{{ __('Mark all as read') }}</flux:button>
                @endif
            </div>

            <flux:menu.separator />

            @forelse ($this->notifications as $notification)
                <flux:menu.item
                    wire:key="notification-{{ $notification->id }}"
                    wire:click="open('{{ $notification->id }}')"
                    :icon="$notification->data['icon'] ?? 'bell'"
                    class="items-start! cursor-pointer"
                >
                    <div class="grid min-w-0 flex-1 gap-0.5 {{ $notification->read_at ? 'opacity-60' : '' }}">
                        <span class="flex items-center gap-1.5">
                            @if ($notification->read_at === null)
                                <span class="size-1.5 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
                                <span class="sr-only">{{ __('Unread:') }}</span>
                            @endif
                            <span class="truncate">{{ $notification->data['title'] }}</span>
                        </span>
                        <span class="text-xs font-normal whitespace-normal text-zinc-500 dark:text-zinc-400">{{ $notification->data['body'] }}</span>
                        <span class="text-xs font-normal text-zinc-400">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                </flux:menu.item>
            @empty
                <flux:text class="px-2 py-6 text-center">{{ __('Nothing new.') }}</flux:text>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
