<?php

use App\Actions\AssignShift;
use App\Models\Shift;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Shift $shift;

    public ?int $employeeId = null;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(Shift $shift): void
    {
        Gate::authorize('access-workforce');

        $this->shift = $shift->load(['outlet', 'createdBy', 'assignments.user', 'assignments.assignedBy']);
    }

    /**
     * Name the tab after the shift being worked on.
     */
    public function rendering(View $view): void
    {
        $view->title(__(':shift on :date', [
            'shift' => $this->shift->name,
            'date' => $this->shift->starts_at->format('d M'),
        ]));
    }

    /**
     * Everyone who could be put on it, minus whoever already is.
     *
     * People already working elsewhere at the time are still listed: the clash
     * is explained when it is refused, which says more than a name quietly
     * missing from a list.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->whereKeyNot($this->shift->assignments->pluck('user_id')->all())
            ->orderBy('name')
            ->get();
    }

    /**
     * Put an employee on the shift.
     */
    public function assign(): void
    {
        Gate::authorize('access-workforce');

        $validated = $this->validate([
            'employeeId' => ['required', 'integer', 'exists:users,id'],
        ]);

        $employee = User::findOrFail($validated['employeeId']);

        try {
            app(AssignShift::class)->handle($this->shift, $employee, Auth::user());
        } catch (\RuntimeException $exception) {
            $this->addError('employeeId', $exception->getMessage());

            return;
        }

        $this->reset('employeeId');
        $this->refreshShift();

        Flux::toast(variant: 'success', text: __(':name is on this shift.', ['name' => $employee->name]));
    }

    /**
     * Take an employee off the shift.
     */
    public function unassign(int $assignmentId): void
    {
        Gate::authorize('access-workforce');

        // Only assignments this shift owns may be removed — an id belonging to
        // another shift is not this screen's to touch.
        $this->shift->assignments()->whereKey($assignmentId)->delete();

        $this->refreshShift();

        Flux::toast(variant: 'success', text: __('Taken off the shift.'));
    }

    /**
     * Re-read the shift and the pickers built from it.
     */
    protected function refreshShift(): void
    {
        $this->shift = $this->shift->fresh(['outlet', 'createdBy', 'assignments.user', 'assignments.assignedBy']);

        unset($this->candidates);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $shift->name }}</flux:heading>
            <flux:text class="mt-2">
                {{ $shift->starts_at->format('l, d M Y') }} &middot;
                {{ $shift->starts_at->format('g:ia') }}&ndash;{{ $shift->ends_at->format('g:ia') }}
                ({{ $shift->duration() }}) &middot; {{ $shift->outlet->name }}
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:button size="sm" variant="ghost" :href="route('employee.workforce.shifts.edit', $shift)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
            <flux:button size="sm" variant="ghost" :href="route('employee.workforce', ['from' => $shift->starts_at->startOfWeek()->toDateString()])" wire:navigate>
                {{ __('Back to workforce') }}
            </flux:button>
        </div>
    </div>

    @if ($shift->notes)
        <flux:callout icon="pencil" class="mt-6">
            <flux:callout.text>{{ $shift->notes }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:heading size="lg">{{ __('Who is on it') }}</flux:heading>

            @if ($shift->assignments->isEmpty())
                <flux:callout icon="users" class="mt-4">
                    <flux:callout.heading>{{ __('Nobody assigned') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('This shift is on the schedule but has nobody working it.') }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Employee') }}</flux:table.column>
                            <flux:table.column>{{ __('Role') }}</flux:table.column>
                            <flux:table.column>{{ __('Assigned by') }}</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($shift->assignments as $assignment)
                                <flux:table.row :key="$assignment->id">
                                    <flux:table.cell class="font-medium">{{ $assignment->user->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="purple">{{ $assignment->user->role->label() }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $assignment->assignedBy->name }}</flux:table.cell>
                                    <flux:table.cell class="text-end">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="unassign({{ $assignment->id }})"
                                            wire:confirm="{{ __('Take :name off this shift?', ['name' => $assignment->user->name]) }}"
                                        >
                                            {{ __('Remove') }}
                                        </flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>

        <div>
            <flux:card>
                <flux:heading size="lg">{{ __('Assign someone') }}</flux:heading>

                @if ($this->candidates->isEmpty())
                    <flux:text class="mt-2">{{ __('Everyone is already on this shift.') }}</flux:text>
                @else
                    <form wire:submit="assign" class="mt-4 flex flex-col gap-4">
                        <flux:select wire:model="employeeId" :label="__('Employee')" required>
                            <flux:select.option value="">{{ __('Choose an employee') }}</flux:select.option>
                            @foreach ($this->candidates as $employee)
                                <flux:select.option :value="$employee->id">
                                    {{ $employee->name }} &middot; {{ $employee->role->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button variant="primary" type="submit">{{ __('Assign') }}</flux:button>
                    </form>
                @endif
            </flux:card>

            <flux:card class="mt-4">
                <flux:heading size="sm">{{ __('Scheduled by') }}</flux:heading>
                <flux:text class="mt-2">{{ $shift->createdBy->name }}</flux:text>
            </flux:card>
        </div>
    </div>
</section>
