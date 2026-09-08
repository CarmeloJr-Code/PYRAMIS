<?php

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Workforce')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $weekStart = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-workforce');

        if ($this->weekStart === '') {
            $this->weekStart = now()->startOfWeek()->toDateString();
        }
    }

    /**
     * The seven days the roster is showing.
     *
     * @return Collection<int, \Carbon\CarbonImmutable>
     */
    #[Computed]
    public function days(): Collection
    {
        $start = now()->parse($this->weekStart)->startOfDay();

        return collect(range(0, 6))->map(fn (int $offset) => $start->addDays($offset));
    }

    /**
     * The week's shifts, grouped by the day they start on.
     *
     * @return Collection<string, Collection<int, Shift>>
     */
    #[Computed]
    public function shiftsByDay(): Collection
    {
        return Shift::query()
            ->with(['outlet', 'assignments.user'])
            ->whereBetween('starts_at', [
                $this->days->first()->startOfDay(),
                $this->days->last()->endOfDay(),
            ])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Shift $shift): string => $shift->starts_at->toDateString());
    }

    /**
     * How many shifts in the week have nobody on them.
     */
    #[Computed]
    public function unstaffedCount(): int
    {
        return $this->shiftsByDay
            ->flatten()
            ->filter(fn (Shift $shift): bool => $shift->assignments->isEmpty())
            ->count();
    }

    /**
     * Employees with nothing on the roster this week, so an Administrator can
     * see who is spare rather than working it out from the grid.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function unscheduled(): Collection
    {
        $assigned = $this->shiftsByDay
            ->flatten()
            ->flatMap(fn (Shift $shift): array => $shift->assignments->pluck('user_id')->all())
            ->unique();

        return User::query()
            ->whereKeyNot($assigned->all())
            ->orderBy('name')
            ->get();
    }

    /**
     * Step the roster a week at a time.
     */
    public function shiftWeek(int $weeks): void
    {
        $this->weekStart = now()->parse($this->weekStart)->addWeeks($weeks)->toDateString();

        unset($this->days, $this->shiftsByDay, $this->unstaffedCount, $this->unscheduled);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Workforce') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Who is working, where, and when.') }}</flux:text>
        </div>

        <flux:button :href="route('employee.workforce.shifts.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New shift') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftWeek(-1)" />
            <span class="text-sm font-medium">
                {{ $this->days->first()->format('d M') }} &ndash; {{ $this->days->last()->format('d M Y') }}
            </span>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftWeek(1)" />
        </div>

        @if ($this->unstaffedCount > 0)
            <flux:badge color="amber">
                {{ trans_choice('{1} :count shift has nobody on it|[2,*] :count shifts have nobody on them', $this->unstaffedCount, ['count' => $this->unstaffedCount]) }}
            </flux:badge>
        @endif
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->days as $day)
            @php($shifts = $this->shiftsByDay->get($day->toDateString(), collect()))

            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="day-{{ $day->toDateString() }}">
                <div class="flex items-baseline justify-between">
                    <span class="font-medium">{{ $day->format('D') }}</span>
                    <span class="text-sm text-zinc-500">{{ $day->format('d M') }}</span>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @forelse ($shifts as $shift)
                        <a
                            href="{{ route('employee.workforce.shifts.show', $shift) }}"
                            class="block rounded-md bg-zinc-50 p-3 transition hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                            wire:navigate
                            wire:key="shift-{{ $shift->id }}"
                        >
                            <p class="font-medium">{{ $shift->name }}</p>
                            <p class="text-sm text-zinc-500">
                                {{ $shift->starts_at->format('g:ia') }}&ndash;{{ $shift->ends_at->format('g:ia') }}
                                &middot; {{ $shift->outlet->name }}
                            </p>

                            <div class="mt-2 flex flex-wrap gap-1">
                                @forelse ($shift->assignments as $assignment)
                                    <flux:badge size="sm" color="purple">{{ $assignment->user->name }}</flux:badge>
                                @empty
                                    <flux:badge size="sm" color="amber">{{ __('Nobody assigned') }}</flux:badge>
                                @endforelse
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-zinc-400">{{ __('Nothing scheduled') }}</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    @if ($this->unscheduled->isNotEmpty())
        <flux:separator variant="subtle" class="my-6" />

        <flux:heading size="lg">{{ __('Not on the roster this week') }}</flux:heading>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($this->unscheduled as $employee)
                <flux:badge color="zinc" wire:key="spare-{{ $employee->id }}">
                    {{ $employee->name }} &middot; {{ $employee->role->label() }}
                </flux:badge>
            @endforeach
        </div>
    @endif
</section>
