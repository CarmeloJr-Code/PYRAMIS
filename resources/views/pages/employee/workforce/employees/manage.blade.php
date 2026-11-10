<?php

use App\Actions\ProvisionEmployee;
use App\Concerns\EmailValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use App\Rules\PhilippineMobile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage employee')] class extends Component {
    use EmailValidationRules;

    #[Locked]
    public ?int $employeeId = null;

    public string $name = '';

    public string $email = '';

    /**
     * Optional. Texts about their shifts go here; with none they aren't texted.
     */
    public string $phone = '';

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
            $this->phone = $user->phone ?? '';
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

        // Livewire skips the TrimStrings middleware, so a stray space would
        // otherwise be stored, and slip a duplicate email past unique.
        $this->name = trim($this->name);
        $this->email = $this->normaliseEmail($this->email);
        $this->phone = trim($this->phone);

        $validated = $this->validate([
            // The same letters a customer's name is held to on the storefront.
            'name' => ['required', 'string', 'min:2', 'max:255', "regex:/^[\\pL\\s.'\\-]+$/u"],
            'email' => $this->employeeEmailRules($this->employeeId),
            'phone' => ['nullable', 'string', new PhilippineMobile],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['boolean'],
        ], [
            'name.regex' => __('Please use letters only in the name.'),
        ]);

        if ($this->employeeId === null) {
            [$employee, $password] = app(ProvisionEmployee::class)->handle(
                $validated['name'],
                $validated['email'],
                UserRole::from($validated['role']),
                $validated['phone'] ?: null,
            );

            $this->employeeId = $employee->id;
            $this->generatedPassword = $password;

            Flux::toast(variant: 'success', text: __('Employee created.'));

            return;
        }

        $employee = User::findOrFail($this->employeeId);

        $role = UserRole::from($validated['role']);

        // The same two promises the list's switch keeps, because this form can
        // do everything the switch can. Closing yourself ends the session doing
        // it with no way back in.
        if ($employee->is(Auth::user()) && ! $validated['is_active']) {
            $this->addError('is_active', __('You cannot close your own account.'));

            return;
        }

        // And the business must keep a way in: the last Administrator standing
        // can be neither closed nor made something else.
        if ($employee->isLastActiveAdministrator() && (! $validated['is_active'] || $role !== UserRole::Administrator)) {
            $this->addError(
                $validated['is_active'] ? 'role' : 'is_active',
                __('This is the last active administrator. Make someone else an administrator first.'),
            );

            return;
        }

        // No password here. An employee changes their own under Settings, and
        // an Administrator resetting one by hand would mean choosing it for
        // them and carrying it across.
        $employee->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
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
    <flux:text class="mt-2">{{ __('There is no public sign-up, so employee accounts are created here.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($generatedPassword !== null)
        <flux:callout icon="key" variant="warning" class="mb-6">
            <flux:callout.heading>{{ __('First sign-in password') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Copy it now. It won\'t be shown again. Ask them to change it after they sign in.') }}
            </flux:callout.text>
            <p class="mt-3 font-mono text-lg break-all select-all">{{ $generatedPassword }}</p>
        </flux:callout>

        <flux:button :href="route('employee.workforce.employees.index')" variant="primary" wire:navigate>
            {{ __('Done') }}
        </flux:button>
    @else
        {{-- keep() drops what the rules in save() would refuse as it is typed
             or pasted, then repeats the input event: wire:model reads the field
             before keep() runs, and would otherwise hold the refused text. The
             rules in save() stay the authority. --}}
        <form
            wire:submit="save"
            x-data="{ keep(el, refused) { const kept = el.value.replace(refused, ''); if (kept !== el.value) { el.value = kept; el.dispatchEvent(new Event('input')) } } }"
            class="flex flex-col gap-6"
        >
            <flux:input
                wire:model="name"
                :label="__('Full name')"
                maxlength="255"
                autocomplete="off"
                x-on:input="keep($el, /[^\p{L}\s.'-]/gu)"
                required
                autofocus
            />

            <flux:input
                wire:model="email"
                :label="__('Email address')"
                type="email"
                maxlength="255"
                autocomplete="off"
                x-on:input="keep($el, /\s/g)"
                required
            />

            <flux:input
                wire:model="phone"
                x-on:input="keep($el, /\D/g); if ($el.value.length > 11) { $el.value = $el.value.slice(0, 11); $el.dispatchEvent(new Event('input')) }"
                :label="__('Mobile number')"
                type="tel"
                inputmode="numeric"
                autocomplete="off"
                placeholder="09171234567"
                :description="__('Optional. Their shifts are texted to this number.')"
            />

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
                <flux:error name="is_active" />
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
