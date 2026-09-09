<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    //
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
    <flux:text class="mt-2">{{ __('What the business did over a stretch of days, read from the records as they were kept.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    {{--
        Only the reports this role can open are listed. Each one carries the
        gate that guards the screens its figures come from, so the matrix's
        "relevant subset" needs no separate list of who sees what.
    --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @can('access-sales')
            <flux:card>
                <flux:heading size="lg">{{ __('Sales') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Takings day by day, what sold, where it sold, and how the stretch compares with the one before it.') }}</flux:text>
                <flux:button :href="route('employee.reports.sales')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-inventory')
            <flux:card>
                <flux:heading size="lg">{{ __('Inventory') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Stock as it stands, what is at or below its reorder level, and what moved through the stockroom.') }}</flux:text>
                <flux:button :href="route('employee.reports.inventory')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-production')
            <flux:card>
                <flux:heading size="lg">{{ __('Production') }}</flux:heading>
                <flux:text class="mt-2">{{ __('How much came out of the oven, on which days, and of what.') }}</flux:text>
                <flux:button :href="route('employee.reports.production')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-expenses')
            <flux:card>
                <flux:heading size="lg">{{ __('Expenses') }}</flux:heading>
                <flux:text class="mt-2">{{ __('What was spent, under which heading, and at which location.') }}</flux:text>
                <flux:button :href="route('employee.reports.expenses')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('access-workforce')
            <flux:card>
                <flux:heading size="lg">{{ __('Workforce') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Rostered shifts, who was assigned to them, and what each employee put on the record.') }}</flux:text>
                <flux:button :href="route('employee.reports.workforce')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan

        @can('manage-outlets')
            <flux:card>
                <flux:heading size="lg">{{ __('Outlet performance') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Every location side by side: what it took, what it received, and what it cost to run.') }}</flux:text>
                <flux:button :href="route('employee.reports.outlets')" variant="ghost" size="sm" class="mt-4" wire:navigate>
                    {{ __('Open') }}
                </flux:button>
            </flux:card>
        @endcan
    </div>
</section>
