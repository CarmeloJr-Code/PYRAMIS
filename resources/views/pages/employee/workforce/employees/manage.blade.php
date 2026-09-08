<?php

use App\Actions\ProvisionEmployee;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage employee')] class extends Component {
    #[Locked]
    public ?int $employeeId = null;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public bool $is_active = true;

    /**
     * The password minted for a new account, shown once and never again.
     */
    #[Locked]
    public ?string $generatedPassword = null;

    /**
     * Mount the component for either a new or an existing employee.
     */
    public function mount(?User $user = null): void
    {
        Gate::authorize('access-workforce');

        if ($user?->exists) {
            $this->employeeId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role->value;
            $this->is_active = $user->is_active;
        }
    }

    /**
     * The roles an employee can hold.
     *
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return UserRole::cases();
    }

    /**
     * Create or update the employee.
     */
    public function save(): void
    {
        Gate::authorize('access-workforce');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->employeeId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['boolean'],
        ]);

        if ($this->employeeId === null) {
            [$employee, $password] = app(ProvisionEmployee::class)->handle(
                $validated['name'],
                $validated['email'],
                UserRole::from($validated['role']),
            );

            $this->employeeId = $employee->id;
            $this->generatedPassword = $password;

            Flux::toast(variant: 'success', text: __('Employee created.'));

            return;
        }

        $employee = User::findOrFail($this->employeeId);

        // No password here. An employee changes their own under Settings, and
        // an Administrator resetting one by hand would mean choosing it for
        // them and carrying it across.
        $employee->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
            'is_active' => $validated['is_active'],
        ]);

        Flux::toast(variant: 'success', text: __('Employee saved.'));

        $this->redirectRoute('employee.workforce.employees.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $employeeId === null ? __('New employee') : __('Edit employee') }}
    </flux:heading>
    <flux:text class="mt-2">{{ __('PYRAMIS has no public sign-up, so accounts are created here or with the make:employee command.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($generatedPassword !== null)
        <flux:callout icon="key" variant="warning" class="mb-6">
            <flux:callout.heading>{{ __('First sign-in password') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Copy it now — it is not stored and will not be shown again. Have them change it after signing in.') }}
            </flux:callout.text>
            <p class="mt-3 font-mono text-lg break-all select-all">{{ $generatedPassword }}</p>
        </flux:callout>

        <flux:button :href="route('employee.workforce.employees.index')" variant="primary" wire:navigate>
            {{ __('Done') }}
        </flux:button>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Full name')" required autofocus />

            <flux:input wire:model="email" :label="__('Email address')" type="email" required />

            <flux:select wire:model="role" :label="__('Role')" required>
                <flux:select.option value="">{{ __('Choose a role') }}</flux:select.option>
                @foreach ($this->roles() as $role)
                    <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($employeeId !== null)
                <flux:switch
                    wire:model="is_active"
                    :label="__('Account open')"
                    :description="__('A closed account keeps its history but cannot sign in.')"
                />
            @else
                <flux:text class="text-sm">
                    {{ __('A password is generated when the account is created and shown once.') }}
                </flux:text>
            @endif

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">
                    {{ $employeeId === null ? __('Create employee') : __('Save employee') }}
                </flux:button>
                <flux:button variant="ghost" :href="route('employee.workforce.employees.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
