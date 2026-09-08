<?php

use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('My schedule')] class extends Component {
    #[Url(as: 'past', except: false)]
    public bool $includePast = false;

    /**
     * The signed-in employee's own shifts.
     *
     * Scoped to the authenticated user rather than to an id from the request:
     * there is no way to ask this screen for somebody else's roster (BR-012).
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        return Shift::query()
            ->with('outlet')
            ->whereHas('assignments', fn ($query) => $query->where('user_id', Auth::id()))
            ->unless($this->includePast, fn ($query) => $query->where('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The next shift due, which is what an employee opens this for.
     */
    #[Computed]
    public function next(): ?Shift
    {
        return $this->shifts->first(fn (Shift $shift): bool => $shift->ends_at->isFuture());
    }

    /**
     * The shifts grouped by the day they start on.
     *
     * @return Collection<string, Collection<int, Shift>>
     */
    #[Computed]
    public function byDay(): Collection
    {
        return $this->shifts->groupBy(fn (Shift $shift): string => $shift->starts_at->toDateString());
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('My schedule') }}</flux:heading>
            <flux:text class="mt-2">{{ __('The shifts you are assigned to.') }}</flux:text>
        </div>

        <flux:switch wire:model.live="includePast" :label="__('Include past shifts')" />
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->next)
        <flux:card class="mb-6">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Next shift') }}</span>
            <p class="mt-1 text-2xl font-semibold">{{ $this->next->name }}</p>
            <flux:text class="mt-1">
                {{ $this->next->starts_at->format('l, d M') }} &middot;
                {{ $this->next->starts_at->format('g:ia') }}&ndash;{{ $this->next->ends_at->format('g:ia') }}
                &middot; {{ $this->next->outlet->name }}
            </flux:text>
        </flux:card>
    @endif

    @if ($this->shifts->isEmpty())
        <flux:callout icon="calendar">
            <flux:callout.heading>{{ __('Nothing scheduled') }}</flux:callout.heading>
            <flux:callout.text>{{ __('You have no shifts assigned. Your manager sets the roster.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($this->byDay as $date => $shifts)
                <div wire:key="day-{{ $date }}">
                    <flux:heading size="lg">{{ $shifts->first()->starts_at->format('l, d M Y') }}</flux:heading>

                    <div class="mt-3 flex flex-col gap-3">
                        @foreach ($shifts as $shift)
                            <div
                                class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 {{ $shift->ends_at->isPast() ? 'opacity-60' : '' }}"
                                wire:key="shift-{{ $shift->id }}"
                            >
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="font-medium">{{ $shift->name }}</p>
                                    <p class="text-sm text-zinc-500">
                                        {{ $shift->starts_at->format('g:ia') }}&ndash;{{ $shift->ends_at->format('g:ia') }}
                                        ({{ $shift->duration() }})
                                    </p>
                                </div>

                                <p class="mt-1 text-sm text-zinc-500">{{ $shift->outlet->name }}</p>

                                @if ($shift->notes)
                                    <p class="mt-2 text-sm">{{ $shift->notes }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
