{{--
    A day-by-day line, oldest first. Decoration only: whatever it shows is also
    said in words beside it, so it is hidden from screen readers.
--}}
@props(['points'])

@php
    $values = array_values($points);
    $last = max(count($values) - 1, 1);
    $peak = max(1, ...$values);

    // x across 0–100, y down from 22 to 2, so the line never touches the edge.
    $line = collect($values)
        ->map(fn (int $value, int $index): string => round($index / $last * 100, 2).','.round(22 - $value / $peak * 20, 2))
        ->implode(' ');
@endphp

<svg viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true" {{ $attributes->class('h-8 w-full text-purple-600 dark:text-purple-400') }}>
    <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
</svg>
