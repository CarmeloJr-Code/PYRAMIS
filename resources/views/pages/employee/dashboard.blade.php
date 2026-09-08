<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * The signed-in employee.
     */
    #[Computed]
    public function employee(): User
    {
        return Auth::user();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $this->employee->name]) }}</flux:heading>

        <div class="flex items-center gap-2">
            <flux:badge size="sm" color="purple" data-test="role-badge">{{ $this->employee->role->label() }}</flux:badge>
            <flux:text>{{ __('Purple Yam Malaybalay') }}</flux:text>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-4 md:grid-cols-3">
        @can('manage-products')
            <flux:card>
                <flux:heading size="lg">{{ __('Products') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Manage the catalogue, sizes and prices.') }}</flux:text>
                <flux:button :href="route('employee.products.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('manage-orders')
            <flux:card>
                <flux:heading size="lg">{{ __('Orders') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Confirm customer pre-orders and move them to pickup.') }}</flux:text>
                <flux:button :href="route('employee.orders.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-sales')
            <flux:card>
                <flux:heading size="lg">{{ __('Sales') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Record transactions and manage customer orders.') }}</flux:text>
                <flux:button :href="route('employee.sales.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-inventory')
            <flux:card>
                <flux:heading size="lg">{{ __('Inventory') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Track ingredient stock and record what comes in and out.') }}</flux:text>
                <flux:button :href="route('employee.inventory.index')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-production')
            <flux:card>
                <flux:heading size="lg">{{ __('Production') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Log baking runs, ingredient usage and product stock.') }}</flux:text>
                <flux:button :href="route('employee.production')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-workforce')
            <flux:card>
                <flux:heading size="lg">{{ __('Workforce') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Manage employee schedules and assignments.') }}</flux:text>
                <flux:button :href="route('employee.workforce')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan
    </div>
</section>
