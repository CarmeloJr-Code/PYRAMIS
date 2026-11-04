{{--
    A rise or fall, coloured by whether it is good news: green for the good
    direction, amber for the one to watch, plain when level. The slot carries
    the signed figure itself, so the change still reads without colour.

    Set rising-is-good to false where more is worse, as with spending.
--}}
@props([
    'value',
    'risingIsGood' => true,
])

@php
    $good = $risingIsGood ? $value > 0 : $value < 0;
    $bad = $risingIsGood ? $value < 0 : $value > 0;
@endphp

<span {{ $attributes->class(['text-success' => $good, 'text-warning' => $bad]) }}>{{ $slot }}</span>
