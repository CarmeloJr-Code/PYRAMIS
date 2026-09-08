<?php

use App\Models\Restock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Restocking')] class extends Component {
    #[Url(as: 'all', except: false)]
    public bool $includeFinished = false;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-restocking');
    }

    /**
     * The restock queue, in the order it is worked through.
     *
     * @return Collection<int, Restock>
     */
    #[Computed]
    public function restocks(): Collection
    {
        return Restock::query()
            ->with(['outlet', 'items', 'requestedBy'])
            ->unless($this->includeFinished, fn ($query) => $query->open())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many are still to be dealt with.
     */
    #[Computed]
    public function openCount(): int
    {
        return Restock::query()->open()->count();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Restocking') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Finished goods going from the main branch out to the outlets.') }}</flux:text>
        </div>

        @can('schedule-restocks')
            <flux:button :href="route('employee.restocks.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('Schedule restock') }}
            </flux:button>
        @endcan
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:switch wire:model.live="includeFinished" :label="__('Show delivered and cancelled')" />

        @if ($this->openCount > 0)
            <flux:badge color="amber">
                {{ trans_choice('{1} :count still open|[2,*] :count still open', $this->openCount, ['count' => $this->openCount]) }}
            </flux:badge>
        @endif
    </div>

    @if ($this->restocks->isEmpty())
        <flux:callout icon="truck">
            <flux:callout.heading>{{ __('Nothing to send') }}</flux:callout.heading>
            <flux:callout.text>
                {{ $includeFinished
                    ? __('No restocks have been scheduled.')
                    : __('Every scheduled restock has been delivered or called off.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Restock') }}</flux:table.column>
                    <flux:table.column>{{ __('Outlet') }}</flux:table.column>
                    <flux:table.column>{{ __('Scheduled') }}</flux:table.column>
                    <flux:table.column>{{ __('Units') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->restocks as $restock)
                        <flux:table.row :key="$restock->id">
                            <flux:table.cell class="font-mono font-medium">{{ $restock->reference }}</flux:table.cell>
                            <flux:table.cell>{{ $restock->outlet->name }}</flux:table.cell>
                            <flux:table.cell>{{ $restock->scheduled_for->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $restock->preparedUnits() > 0 || $restock->isPrepared()
                                    ? $restock->preparedUnits().' / '.$restock->requestedUnits()
                                    : $restock->requestedUnits() }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$restock->status->color()">
                                    {{ $restock->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('employee.restocks.show', $restock)" wire:navigate>
                                    {{ __('Open') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
