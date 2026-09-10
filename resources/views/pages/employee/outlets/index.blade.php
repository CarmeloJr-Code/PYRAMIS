<?php

use App\Models\Outlet;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Outlets')] class extends Component {
    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-outlets');
    }

    /**
     * The pickup locations, with how much work each is carrying.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->withCount('orders')
            ->orderBy('name')
            ->get();
    }

    /**
     * Open or close an outlet for new pickups.
     */
    public function toggleActive(int $outletId): void
    {
        Gate::authorize('manage-outlets');

        $outlet = Outlet::findOrFail($outletId);

        // The main branch runs production and supplies every other outlet
        // (BR-003, BR-004), so closing it would leave finished goods with
        // nowhere to land.
        if ($outlet->is_main_branch && $outlet->is_active) {
            Flux::toast(variant: 'warning', text: __('The main branch cannot be closed.'));

            return;
        }

        $outlet->update(['is_active' => ! $outlet->is_active]);

        unset($this->outlets);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Outlets') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Pickup locations customers can choose when pre-ordering.') }}</flux:text>
        </div>

        <flux:button :href="route('employee.outlets.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New outlet') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->outlets->isEmpty())
        <flux:callout icon="building-storefront">
            <flux:callout.heading>{{ __('No outlets yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Customers cannot pre-order until at least one outlet is open for pickup.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Address') }}</flux:table.column>
                    <flux:table.column>{{ __('Orders') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->outlets as $outlet)
                        <flux:table.row :key="$outlet->id">
                            <flux:table.cell class="font-medium">
                                {{ $outlet->name }}
                                @if ($outlet->is_main_branch)
                                    <flux:badge size="sm" color="purple" class="ms-2">{{ __('Main branch') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $outlet->address }}</flux:table.cell>
                            <flux:table.cell>{{ $outlet->orders_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$outlet->is_active ? 'green' : 'zinc'">
                                    {{ $outlet->is_active ? __('Open') : __('Closed') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                @unless ($outlet->is_main_branch && $outlet->is_active)
                                    <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $outlet->id }})">
                                        {{ $outlet->is_active ? __('Close') : __('Open') }}
                                    </flux:button>
                                @endunless

                                <flux:button size="sm" variant="ghost" :href="route('employee.outlets.edit', $outlet)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
