<?php

use App\Models\Outlet;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage outlet')] class extends Component {
    #[Locked]
    public ?int $outletId = null;

    public string $name = '';

    public string $address = '';

    public string $phone = '';

    public bool $is_active = true;

    /**
     * Mount the component for either a new or an existing outlet.
     */
    public function mount(?Outlet $outlet = null): void
    {
        Gate::authorize('manage-outlets');

        if ($outlet?->exists) {
            $this->outletId = $outlet->id;
            $this->name = $outlet->name;
            $this->address = $outlet->address;
            $this->phone = $outlet->phone ?? '';
            $this->is_active = $outlet->is_active;
        }
    }

    /**
     * Persist the outlet.
     */
    public function save(): void
    {
        Gate::authorize('manage-outlets');

        // Livewire skips the TrimStrings middleware, so a stray space would
        // otherwise be stored, and slip a duplicate name past unique.
        foreach (['name', 'address', 'phone'] as $property) {
            $this->{$property} = trim($this->{$property});
        }

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('outlets', 'name')->ignore($this->outletId),
            ],
            'address' => ['required', 'string', 'max:500'],
            // A landline as readily as a mobile: digits and the separators
            // people write between their groups, with an optional leading +.
            'phone' => ['nullable', 'string', 'min:7', 'max:20', 'regex:/^\+?[\d\s.()\-]+$/'],
            'is_active' => ['boolean'],
        ], [
            'phone.regex' => __('Please use digits only, like 0917 123 4567 or (088) 221 1234.'),
        ]);

        $outlet = $this->outletId === null
            ? new Outlet
            : Outlet::findOrFail($this->outletId);

        $outlet->fill([
            'name' => $validated['name'],
            'address' => $validated['address'],
            'phone' => $validated['phone'] ?: null,
            'is_active' => $validated['is_active'],
        ])->save();

        Flux::toast(variant: 'success', text: __('Outlet saved.'));

        $this->redirectRoute('employee.outlets.index', navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">
        {{ $outletId === null ? __('New outlet') : __('Edit outlet') }}
    </flux:heading>

    <flux:separator variant="subtle" class="my-6" />

    {{-- keep() drops what the rules in save() would refuse as it is typed
         or pasted, then repeats the input event: wire:model reads the field
         before keep() runs, and would otherwise hold the refused text. The
         rules in save() stay the authority. --}}
    <form
        wire:submit="save"
        x-data="{ keep(el, refused) { const kept = el.value.replace(refused, ''); if (kept !== el.value) { el.value = kept; el.dispatchEvent(new Event('input')) } } }"
        class="flex max-w-2xl flex-col gap-6"
    >
        <flux:input wire:model="name" :label="__('Name')" maxlength="255" required autofocus />

        <flux:textarea wire:model="address" :label="__('Address')" rows="3" maxlength="500" required />

        <flux:input
            wire:model="phone"
            x-on:input="keep($el, /[^\d+\s.()-]/g); keep($el, /(?!^)\+/g)"
            :label="__('Phone')"
            type="tel"
            inputmode="tel"
            maxlength="20"
            placeholder="(088) 221 1234"
            :description="__('Optional. Mobile or landline.')"
        />

        <flux:switch
            wire:model="is_active"
            :label="__('Open for pickup')"
            :description="__('Closed outlets cannot be chosen when a customer pre-orders.')"
        />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save outlet') }}</flux:button>
            <flux:button variant="ghost" :href="route('employee.outlets.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
