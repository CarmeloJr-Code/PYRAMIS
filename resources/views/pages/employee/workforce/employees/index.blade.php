<?php

use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Employees')] class extends Component {
    #[Url(as: 'former', except: false)]
    public bool $includeFormer = false;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-workforce');
    }

    /**
     * The people on the books, with how much work each is carrying.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function employees(): Collection
    {
        return User::query()
            ->withCount('shiftAssignments')
            ->unless($this->includeFormer, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    /**
     * How many people are still working here.
     */
    #[Computed]
    public function activeCount(): int
    {
        return User::query()->active()->count();
    }

    /**
     * Close or reopen an employee's account.
     */
    public function toggleActive(int $userId): void
    {
        Gate::authorize('access-workforce');

        $employee = User::findOrFail($userId);

        // Deactivating yourself would end the session doing it, on the next
        // request, with no way back in.
        if ($employee->is($this->signedIn())) {
            Flux::toast(variant: 'warning', text: __('You cannot close your own account.'));

            return;
        }

        // And the business must keep a way in: the last Administrator standing
        // cannot be the one who is closed.
        if ($employee->is_active && $this->isLastAdministrator($employee)) {
            Flux::toast(variant: 'warning', text: __('This is the last active administrator.'));

            return;
        }

        $employee->update(['is_active' => ! $employee->is_active]);

        unset($this->employees, $this->activeCount);
    }

    /**
     * The signed-in Administrator.
     */
    protected function signedIn(): User
    {
        return Auth::user();
    }

    /**
     * Whether closing this account would leave nobody able to administer.
     */
    protected function isLastAdministrator(User $employee): bool
    {
        if ($employee->role !== UserRole::Administrator) {
            return false;
        }

        return User::query()
            ->active()
            ->where('role', UserRole::Administrator)
            ->whereKeyNot($employee->id)
            ->doesntExist();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Employees') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Who works here, what they do, and whether their account is open.') }}</flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:button size="sm" variant="ghost" :href="route('employee.workforce')" wire:navigate>
                {{ __('Roster') }}
            </flux:button>

            <flux:button :href="route('employee.workforce.employees.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('New employee') }}
            </flux:button>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:switch wire:model.live="includeFormer" :label="__('Show closed accounts')" />

        <flux:badge color="zinc">
            {{ trans_choice('{1} :count active|[2,*] :count active', $this->activeCount, ['count' => $this->activeCount]) }}
        </flux:badge>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Employee') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Shifts') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->employees as $employee)
                    <flux:table.row :key="$employee->id">
                        <flux:table.cell class="font-medium">{{ $employee->name }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $employee->email }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="purple">{{ $employee->role->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $employee->shift_assignments_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$employee->is_active ? 'green' : 'zinc'">
                                {{ $employee->is_active ? __('Active') : __('Closed') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="toggleActive({{ $employee->id }})"
                                wire:key="toggle-{{ $employee->id }}"
                            >
                                {{ $employee->is_active ? __('Close account') : __('Reopen') }}
                            </flux:button>

                            <flux:button size="sm" variant="ghost" :href="route('employee.workforce.employees.edit', $employee)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
