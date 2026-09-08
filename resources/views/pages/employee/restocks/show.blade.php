<?php

use App\Actions\DeliverRestock;
use App\Enums\RestockStatus;
use App\Models\Outlet;
use App\Models\Restock;
use App\Models\RestockItem;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Restock $restock;

    /**
     * The prepared quantity per line, keyed by restock item id.
     *
     * @var array<int, string>
     */
    public array $prepared = [];

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(Restock $restock): void
    {
        Gate::authorize('access-restocking');

        $this->restock = $restock->load(['outlet', 'items.productVariant.product', 'requestedBy', 'preparedBy', 'deliveredBy']);

        $this->syncPrepared();
    }

    /**
     * Name the tab after the restock being worked on.
     */
    public function rendering(View $view): void
    {
        $view->title(__('Restock :reference', ['reference' => $this->restock->reference]));
    }

    /**
     * Start setting the goods aside.
     */
    public function startPreparing(): void
    {
        $this->apply(RestockStatus::Preparing);
    }

    /**
     * Record what the Baker actually set aside.
     */
    public function savePrepared(): void
    {
        Gate::authorize('access-restocking');

        if ($this->restock->status !== RestockStatus::Preparing) {
            Flux::toast(variant: 'danger', text: __('Only a restock being prepared can be counted.'));

            return;
        }

        $mainBranch = Outlet::mainBranch();

        $validated = $this->validate([
            'prepared' => ['required', 'array'],
            'prepared.*' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        // Only lines this restock owns may be written — a submitted id
        // belonging to another restock is ignored, never adopted.
        $items = $this->restock->items->keyBy('id');

        foreach ($validated['prepared'] as $itemId => $quantity) {
            /** @var RestockItem|null $item */
            $item = $items->get($itemId);

            if ($item === null) {
                continue;
            }

            // The shelf is the ceiling: nobody can set aside more than the main
            // branch is holding, and delivery would refuse it anyway.
            $onHand = $item->productVariant->stockAt($mainBranch);

            if ($quantity > $onHand) {
                $this->addError("prepared.{$itemId}", __('Only :count at the main branch.', ['count' => $onHand]));

                return;
            }
        }

        DB::transaction(function () use ($validated, $items): void {
            foreach ($validated['prepared'] as $itemId => $quantity) {
                $items->get($itemId)?->update(['quantity_prepared' => $quantity]);
            }
        });

        // Set directly rather than through fill(): who prepared the goods is
        // part of the record, not something a form may claim.
        $this->restock->prepared_by = Auth::id();
        $this->restock->save();

        $this->refreshRestock();

        Flux::toast(variant: 'success', text: __('Prepared quantities saved.'));
    }

    /**
     * Send it out, moving the goods from the main branch to the outlet.
     */
    public function deliver(): void
    {
        Gate::authorize('access-restocking');

        try {
            app(DeliverRestock::class)->handle($this->restock, Auth::user());
        } catch (\RuntimeException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->refreshRestock();

        Flux::toast(variant: 'success', text: __('Delivered. The outlet now holds the stock.'));
    }

    /**
     * Call the restock off.
     */
    public function cancel(): void
    {
        $this->apply(RestockStatus::Cancelled);
    }

    /**
     * Apply a status change, refusing anything the workflow disallows.
     */
    protected function apply(RestockStatus $status): void
    {
        Gate::authorize('access-restocking');

        try {
            $this->restock->transitionTo($status);
        } catch (\RuntimeException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        if ($status === RestockStatus::Preparing) {
            $this->restock->prepared_by = Auth::id();
            $this->restock->save();
        }

        $this->refreshRestock();
    }

    /**
     * Re-read the restock and the form built from it.
     */
    protected function refreshRestock(): void
    {
        $this->restock = $this->restock->fresh([
            'outlet', 'items.productVariant.product', 'requestedBy', 'preparedBy', 'deliveredBy',
        ]);

        $this->syncPrepared();
    }

    /**
     * Fill the prepared-quantity form from the lines, defaulting to what was
     * asked for — usually right, and quicker to correct than to type.
     */
    protected function syncPrepared(): void
    {
        $this->prepared = $this->restock->items
            ->mapWithKeys(fn (RestockItem $item): array => [
                $item->id => (string) ($item->quantity_prepared ?? $item->quantity_requested),
            ])
            ->all();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" class="font-mono">{{ $restock->reference }}</flux:heading>
                <flux:badge :color="$restock->status->color()">{{ $restock->status->label() }}</flux:badge>
            </div>

            <flux:text class="mt-2">
                {{ __('Main branch to :outlet, scheduled for :date. Raised by :who.', [
                    'outlet' => $restock->outlet->name,
                    'date' => $restock->scheduled_for->format('d M Y'),
                    'who' => $restock->requestedBy->name,
                ]) }}
            </flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.restocks.index')" wire:navigate>
            {{ __('Back to restocking') }}
        </flux:button>
    </div>

    @if ($restock->notes)
        <flux:callout icon="pencil" class="mt-6">
            <flux:callout.text>{{ $restock->notes }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:heading size="lg">{{ __('What is going') }}</flux:heading>

            @if ($restock->status === RestockStatus::Preparing)
                <flux:text class="mt-1">{{ __('Set the quantity actually put aside for each size. A short bake is recorded as it is, not as it was asked for.') }}</flux:text>

                <form wire:submit="savePrepared" class="mt-4 flex flex-col gap-4">
                    @foreach ($restock->items as $item)
                        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 md:flex-row md:items-center md:justify-between dark:border-zinc-700" wire:key="item-{{ $item->id }}">
                            <div>
                                <p class="font-medium">{{ $item->productVariant->product->name }} &middot; {{ $item->productVariant->name }}</p>
                                <p class="text-sm text-zinc-500">
                                    {{ __(':count requested', ['count' => $item->quantity_requested]) }}
                                </p>
                            </div>

                            <div class="md:w-40">
                                <flux:input
                                    wire:model="prepared.{{ $item->id }}"
                                    :label="__('Prepared')"
                                    type="number"
                                    min="0"
                                />
                            </div>
                        </div>
                    @endforeach

                    <flux:button variant="primary" type="submit" class="self-start">{{ __('Save prepared quantities') }}</flux:button>
                </form>
            @else
                <div class="mt-4 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Product') }}</flux:table.column>
                            <flux:table.column>{{ __('Size') }}</flux:table.column>
                            <flux:table.column>{{ __('Requested') }}</flux:table.column>
                            <flux:table.column>{{ __('Prepared') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($restock->items as $item)
                                <flux:table.row :key="$item->id">
                                    <flux:table.cell class="font-medium">{{ $item->productVariant->product->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->productVariant->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->quantity_requested }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $item->quantity_prepared ?? '—' }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </div>

        <div>
            <flux:card>
                <flux:heading size="lg">{{ __('Next step') }}</flux:heading>

                <div class="mt-4 flex flex-col gap-3">
                    @if ($restock->status === RestockStatus::Requested)
                        <flux:text>{{ __('Nothing has been set aside yet.') }}</flux:text>
                        <flux:button variant="primary" wire:click="startPreparing">{{ __('Start preparing') }}</flux:button>
                    @elseif ($restock->status === RestockStatus::Preparing)
                        <flux:text>{{ __('Delivering moves the prepared goods off the main branch shelf and onto the outlet’s.') }}</flux:text>
                        <flux:button
                            variant="primary"
                            wire:click="deliver"
                            wire:confirm="{{ __('Send this restock? The stock moves to the outlet.') }}"
                        >
                            {{ __('Deliver to outlet') }}
                        </flux:button>
                    @elseif ($restock->status === RestockStatus::Delivered)
                        <flux:text>
                            {{ __(':count units arrived at :outlet on :date.', [
                                'count' => $restock->preparedUnits(),
                                'outlet' => $restock->outlet->name,
                                'date' => $restock->delivered_at?->format('d M Y, g:ia'),
                            ]) }}
                        </flux:text>
                        <flux:button variant="ghost" :href="route('employee.inventory.finished', ['outlet' => $restock->outlet_id])" wire:navigate>
                            {{ __('See the outlet’s shelf') }}
                        </flux:button>
                    @else
                        <flux:text>{{ __('This restock was called off and nothing moved.') }}</flux:text>
                    @endif

                    @unless ($restock->status->isTerminal())
                        <flux:button
                            variant="subtle"
                            wire:click="cancel"
                            wire:confirm="{{ __('Call this restock off?') }}"
                        >
                            {{ __('Cancel restock') }}
                        </flux:button>
                    @endunless
                </div>
            </flux:card>

            <flux:card class="mt-4">
                <flux:heading size="sm">{{ __('Trail') }}</flux:heading>
                <dl class="mt-3 flex flex-col gap-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">{{ __('Requested') }}</dt>
                        <dd>{{ $restock->requestedBy->name }}</dd>
                    </div>
                    @if ($restock->preparedBy)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500">{{ __('Prepared') }}</dt>
                            <dd>{{ $restock->preparedBy->name }}</dd>
                        </div>
                    @endif
                    @if ($restock->deliveredBy)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500">{{ __('Delivered') }}</dt>
                            <dd>{{ $restock->deliveredBy->name }}</dd>
                        </div>
                    @endif
                </dl>
            </flux:card>
        </div>
    </div>
</section>
