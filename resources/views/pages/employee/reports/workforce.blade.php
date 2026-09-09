<?php

use App\Concerns\ReportRange;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Workforce report')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-workforce');

        // The month so far is what a report is usually opened to see.
        [$month, $today] = ReportRange::currentMonth();

        $this->from = $this->from ?: $month;
        $this->to = $this->to ?: $today;
    }

    /**
     * The stretch of days this report covers.
     */
    #[Computed]
    public function range(): ReportRange
    {
        return ReportRange::between($this->from, $this->to);
    }

    /**
     * Jump to one of the stretches a manager asks for by name.
     */
    public function setRange(string $preset): void
    {
        [$this->from, $this->to] = ReportRange::preset($preset);

        unset($this->range);
    }

    /**
     * The shifts rostered over the range, earliest first.
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function roster(): Collection
    {
        return Shift::query()
            ->with(['outlet', 'assignments.user'])
            ->startingBetween($this->range->from(), $this->range->to())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * How many places on the roster were filled.
     */
    #[Computed]
    public function assignments(): int
    {
        return (int) $this->roster->sum(fn (Shift $shift): int => $shift->assignments->count());
    }

    /**
     * How many rostered minutes those places came to.
     */
    #[Computed]
    public function rosteredMinutes(): int
    {
        return (int) $this->roster->sum(
            fn (Shift $shift): int => $shift->durationInMinutes() * $shift->assignments->count(),
        );
    }

    /**
     * One row per employee: what they were rostered for, and what they put on
     * the record.
     *
     * The roster half is added up in PHP from the shifts already loaded above,
     * rather than asked of the database again — and subtracting timestamps in
     * SQL is spelt differently on SQLite and Postgres, which the test suite
     * runs on in turn.
     *
     * @return Collection<int, array{id: int, name: string, role: \App\Enums\UserRole, is_active: bool, sales: int, runs: int, movements: int, expenses: int, total: int, shifts: int, minutes: int}>
     */
    #[Computed]
    public function employees(): Collection
    {
        $rostered = [];

        foreach ($this->roster as $shift) {
            foreach ($shift->assignments as $assignment) {
                $id = $assignment->user_id;

                $rostered[$id] ??= ['shifts' => 0, 'minutes' => 0];
                $rostered[$id]['shifts']++;
                $rostered[$id]['minutes'] += $shift->durationInMinutes();
            }
        }

        return User::recordedBetween($this->range->from(), $this->range->to())
            ->map(fn (array $row): array => $row + [
                'shifts' => $rostered[$row['id']]['shifts'] ?? 0,
                'minutes' => $rostered[$row['id']]['minutes'] ?? 0,
            ])
            // A closed account still belongs in the report for a period it
            // worked in; it does not belong there forever with nothing but
            // zeroes against it.
            ->filter(fn (array $row): bool => $row['is_active'] || $row['total'] > 0 || $row['shifts'] > 0)
            ->values();
    }

    /**
     * A stretch of minutes as hours and minutes.
     */
    public function asHours(int $minutes): string
    {
        return Shift::formatMinutes($minutes);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Workforce report') }}</flux:heading>
            <flux:text class="mt-2">{{ $this->range->label() }}</flux:text>
        </div>

        <flux:button :href="route('employee.reports.index')" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('All reports') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <x-report-range />

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Shifts rostered') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->roster->count()) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Dated by the day each one starts') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Assignments') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ number_format($this->assignments) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Places on the roster filled') }}</p>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Rostered hours') }}</span>
            <p class="mt-1 text-3xl font-semibold">{{ $this->asHours($this->rosteredMinutes) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Planned, not clocked') }}</p>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('By employee') }}</flux:heading>
    <flux:text class="mt-1">{{ __('What each employee was rostered for, and what they put on the record.') }}</flux:text>

    <div class="mt-4 overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Employee') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Shifts') }}</flux:table.column>
                <flux:table.column>{{ __('Hours') }}</flux:table.column>
                <flux:table.column>{{ __('Sales') }}</flux:table.column>
                <flux:table.column>{{ __('Runs') }}</flux:table.column>
                <flux:table.column>{{ __('Stock entries') }}</flux:table.column>
                <flux:table.column>{{ __('Expenses') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->employees as $row)
                    <flux:table.row wire:key="employee-{{ $row['id'] }}">
                        <flux:table.cell class="font-medium">
                            {{ $row['name'] }}
                            @unless ($row['is_active'])
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Closed') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell>{{ $row['role']->label() }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['shifts']) }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $this->asHours($row['minutes']) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['sales']) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['runs']) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['movements']) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($row['expenses']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Schedules') }}</flux:heading>

    @if ($this->roster->isEmpty())
        <flux:callout icon="calendar" class="mt-4">
            <flux:callout.heading>{{ __('Nothing rostered') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No shift starts on these days.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-4 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Starts') }}</flux:table.column>
                    <flux:table.column>{{ __('Ends') }}</flux:table.column>
                    <flux:table.column>{{ __('Length') }}</flux:table.column>
                    <flux:table.column>{{ __('Assigned') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->roster as $shift)
                        <flux:table.row :key="$shift->id">
                            <flux:table.cell class="font-medium">{{ $shift->name }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->outlet->name }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->starts_at->format('D d M, g:ia') }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->ends_at->format('D d M, g:ia') }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $shift->duration() }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($shift->assignments->isEmpty())
                                    <span class="text-amber-600 dark:text-amber-400">{{ __('Nobody') }}</span>
                                @else
                                    {{ $shift->assignments->pluck('user.name')->join(', ') }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
