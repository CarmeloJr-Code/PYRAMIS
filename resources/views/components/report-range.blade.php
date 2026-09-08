{{--
    The date range every report screen is read through.

    Bound straight to the `from` and `to` properties each report component
    declares, so the control and the query never drift apart. What those two
    dates mean is App\Concerns\ReportRange's business.
--}}
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end gap-4']) }}>
    <flux:input wire:model.live="from" type="date" :label="__('From')" class="max-w-44" />
    <flux:input wire:model.live="to" type="date" :label="__('To')" class="max-w-44" />

    <div class="flex flex-wrap gap-1 pb-1">
        <flux:button size="sm" variant="ghost" wire:click="setRange('today')">{{ __('Today') }}</flux:button>
        <flux:button size="sm" variant="ghost" wire:click="setRange('week')">{{ __('This week') }}</flux:button>
        <flux:button size="sm" variant="ghost" wire:click="setRange('month')">{{ __('This month') }}</flux:button>
        <flux:button size="sm" variant="ghost" wire:click="setRange('quarter')">{{ __('90 days') }}</flux:button>
    </div>

    {{ $slot }}
</div>
