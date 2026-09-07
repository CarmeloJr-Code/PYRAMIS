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

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('outlets', 'name')->ignore($this->outletId),
            ],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
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

    <form wire:submit="save" class="flex max-w-2xl flex-col gap-6">
        <flux:input wire:model="name" :label="__('Name')" required autofocus />

        <flux:textarea wire:model="address" :label="__('Address')" rows="3" required />

        <flux:input wire:model="phone" :label="__('Phone')" :description="__('Optional.')" />

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
