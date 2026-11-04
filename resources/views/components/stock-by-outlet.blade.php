@props([
    'outlets',
    'units' => [],
])

{{-- What is left of one size today at each pickup outlet, as
     CountAvailableStock worked it out. Zero only closes "Buy now" — a
     pre-order for a later day is baked on top, so it stays open. --}}
<span {{ $attributes->class('flex flex-col gap-1 text-sm') }}>
    @foreach ($outlets as $outlet)
        @php($count = $units[$outlet->id] ?? 0)

        <span class="flex items-baseline justify-between gap-3" wire:key="stock-{{ $outlet->id }}">
            <span class="text-snow-500">{{ $outlet->name }}</span>
            <span class="{{ $count > 0 ? 'font-medium text-green-700' : 'text-amber-700' }}">
                {{ $count > 0 ? __(':count available today', ['count' => $count]) : __('Out of stock today') }}
            </span>
        </span>
    @endforeach
</span>
