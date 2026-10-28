@props([
    'outlets',
    'units' => [],
])

{{-- What is left of one size at each pickup outlet, as CountAvailableStock
     worked it out. Zero is still open for pre-order, so it says so. --}}
<span {{ $attributes->class('flex flex-col gap-1 text-sm') }}>
    @foreach ($outlets as $outlet)
        @php($count = $units[$outlet->id] ?? 0)

        <span class="flex items-baseline justify-between gap-3" wire:key="stock-{{ $outlet->id }}">
            <span class="text-snow-500">{{ $outlet->name }}</span>
            <span class="{{ $count > 0 ? 'font-medium text-orchid-700' : 'text-snow-400' }}">
                {{ $count > 0 ? __(':count available', ['count' => $count]) : __('0 available — pre-order') }}
            </span>
        </span>
    @endforeach
</span>
