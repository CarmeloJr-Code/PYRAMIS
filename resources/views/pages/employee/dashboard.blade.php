<?php

use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductionRun;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\Shift;
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

    /**
     * Today, as every figure here reads it.
     */
    #[Computed]
    public function today(): string
    {
        return now()->toDateString();
    }

    /**
     * Takings so far today, formatted.
     */
    #[Computed]
    public function salesToday(): string
    {
        return number_format(Sale::takingsInCentavos($this->today, $this->today) / 100, 2);
    }

    /**
     * Takings so far this month, formatted.
     */
    #[Computed]
    public function salesThisMonth(): string
    {
        return number_format(
            Sale::takingsInCentavos(now()->startOfMonth()->toDateString(), $this->today) / 100,
            2,
        );
    }

    /**
     * Spending so far this month, formatted.
     */
    #[Computed]
    public function expensesThisMonth(): string
    {
        return number_format(
            Expense::totalSpentInCentavos(now()->startOfMonth()->toDateString(), $this->today) / 100,
            2,
        );
    }

    /**
     * Orders still to be dealt with.
     */
    #[Computed]
    public function openOrders(): int
    {
        return Order::query()->open()->count();
    }

    /**
     * Units out of the oven today.
     */
    #[Computed]
    public function producedToday(): int
    {
        return ProductionRun::unitsProduced($this->today, $this->today);
    }

    /**
     * Ingredients at or below the level the business set.
     *
     * Counted in PHP over the stocked ingredients, because "low" compares a
     * summed ledger against a per-row threshold — a short list, read once.
     */
    #[Computed]
    public function lowStockCount(): int
    {
        return Ingredient::query()
            ->active()
            ->withStock()
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->isLowStock())
            ->count();
    }

    /**
     * Restocks scheduled but not yet delivered.
     */
    #[Computed]
    public function openRestocks(): int
    {
        return Restock::query()->open()->count();
    }

    /**
     * Outlets currently open.
     */
    #[Computed]
    public function activeOutlets(): int
    {
        return Outlet::query()->active()->count();
    }

    /**
     * Assignments on today's roster.
     */
    #[Computed]
    public function onShiftToday(): int
    {
        return (int) Shift::query()
            ->startingOn($this->today)
            ->withCount('assignments')
            ->get()
            ->sum('assignments_count');
    }

    /**
     * The signed-in employee's next shift, whatever their role.
     */
    #[Computed]
    public function nextShift(): ?Shift
    {
        return Shift::query()
            ->with('outlet')
            ->whereHas('assignments', fn ($query) => $query->where('user_id', Auth::id()))
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Messages waiting for the signed-in employee.
     */
    #[Computed]
    public function unreadMessages(): int
    {
        return Conversation::query()
            ->withMember(Auth::user())
            ->with('participants')
            ->get()
            ->sum(fn (Conversation $conversation): int => $conversation->unreadCountFor(Auth::user()));
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

    {{--
        Each role is shown the figures it acts on. The capability matrix gives
        the Administrator the whole business dashboard and the other two a
        limited one, so a Cashier sees the counter and a Baker sees the kitchen
        rather than everybody seeing everything (BR-012).
    --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @can('access-sales')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Sales today') }}</span>
                <p class="mt-1 text-3xl font-semibold">&#8369;{{ $this->salesToday }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ __(':amount this month', ['amount' => '₱'.$this->salesThisMonth]) }}
                </p>
            </flux:card>
        @endcan

        @can('manage-orders')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Orders waiting') }}</span>
                <p class="mt-1 text-3xl font-semibold">{{ $this->openOrders }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Pre-orders not yet collected or cancelled') }}</p>
            </flux:card>
        @endcan

        @can('access-expenses')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Expenses this month') }}</span>
                <p class="mt-1 text-3xl font-semibold">&#8369;{{ $this->expensesThisMonth }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Recorded across every location') }}</p>
            </flux:card>
        @endcan

        @can('access-production')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Produced today') }}</span>
                <p class="mt-1 text-3xl font-semibold">{{ $this->producedToday }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Units logged out of the oven') }}</p>
            </flux:card>
        @endcan

        @can('access-inventory')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Low on stock') }}</span>
                <p class="mt-1 text-3xl font-semibold {{ $this->lowStockCount > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                    {{ $this->lowStockCount }}
                </p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Ingredients at or below their reorder level') }}</p>
            </flux:card>
        @endcan

        @can('access-restocking')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Restocks open') }}</span>
                <p class="mt-1 text-3xl font-semibold">{{ $this->openRestocks }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Scheduled but not yet delivered') }}</p>
            </flux:card>
        @endcan

        @can('access-workforce')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('On shift today') }}</span>
                <p class="mt-1 text-3xl font-semibold">{{ $this->onShiftToday }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Assignments on today\'s roster') }}</p>
            </flux:card>
        @endcan

        @can('manage-outlets')
            <flux:card>
                <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Outlets open') }}</span>
                <p class="mt-1 text-3xl font-semibold">{{ $this->activeOutlets }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Taking pickups and sales') }}</p>
            </flux:card>
        @endcan

        {{-- Everyone's own two: what they work next, and who is waiting on them. --}}
        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Your next shift') }}</span>
            @if ($this->nextShift)
                <p class="mt-1 text-xl font-semibold">{{ $this->nextShift->name }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ $this->nextShift->starts_at->format('D d M, g:ia') }} &middot; {{ $this->nextShift->outlet->name }}
                </p>
            @else
                <p class="mt-1 text-xl font-semibold text-zinc-400">{{ __('Nothing scheduled') }}</p>
            @endif
            <flux:button :href="route('employee.schedule')" variant="ghost" size="sm" class="mt-3" wire:navigate>
                {{ __('My schedule') }}
            </flux:button>
        </flux:card>

        <flux:card>
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Unread messages') }}</span>
            <p class="mt-1 text-3xl font-semibold {{ $this->unreadMessages > 0 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                {{ $this->unreadMessages }}
            </p>
            <flux:button :href="route('employee.messages.index')" variant="ghost" size="sm" class="mt-3" wire:navigate>
                {{ __('Open messages') }}
            </flux:button>
        </flux:card>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <flux:heading size="lg">{{ __('Go to') }}</flux:heading>

    <div class="mt-4 flex flex-wrap gap-2">
        {{-- The detail behind every tile above. --}}
        <flux:button :href="route('employee.reports.index')" variant="ghost" size="sm" wire:navigate>{{ __('Reports') }}</flux:button>

        @can('manage-products')
            <flux:button :href="route('employee.products.index')" variant="ghost" size="sm" wire:navigate>{{ __('Products') }}</flux:button>
        @endcan

        @can('manage-orders')
            <flux:button :href="route('employee.orders.index')" variant="ghost" size="sm" wire:navigate>{{ __('Orders') }}</flux:button>
        @endcan

        @can('access-sales')
            <flux:button :href="route('employee.sales.index')" variant="ghost" size="sm" wire:navigate>{{ __('Sales') }}</flux:button>
        @endcan

        @can('access-expenses')
            <flux:button :href="route('employee.expenses.index')" variant="ghost" size="sm" wire:navigate>{{ __('Expenses') }}</flux:button>
        @endcan

        @can('access-inventory')
            <flux:button :href="route('employee.inventory.index')" variant="ghost" size="sm" wire:navigate>{{ __('Inventory') }}</flux:button>
        @endcan

        @can('access-restocking')
            <flux:button :href="route('employee.restocks.index')" variant="ghost" size="sm" wire:navigate>{{ __('Restocking') }}</flux:button>
        @endcan

        @can('access-production')
            <flux:button :href="route('employee.production')" variant="ghost" size="sm" wire:navigate>{{ __('Production') }}</flux:button>
        @endcan

        @can('manage-outlets')
            <flux:button :href="route('employee.outlets.index')" variant="ghost" size="sm" wire:navigate>{{ __('Outlets') }}</flux:button>
        @endcan

        @can('access-workforce')
            <flux:button :href="route('employee.workforce')" variant="ghost" size="sm" wire:navigate>{{ __('Workforce') }}</flux:button>
        @endcan
    </div>
</section>
