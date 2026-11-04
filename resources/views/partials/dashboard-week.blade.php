{{--
    The last full week against the one before, in words. Expects $week
    (total and change, from the dashboard) and $total, already formatted.
--}}
<p class="mt-1 text-sm text-zinc-500">
    {{ __(':total in the 7 days to yesterday', ['total' => $total]) }}
    &middot;
    @if ($week['change'] === null)
        {{ __('No earlier week to compare') }}
    @elseif ($week['change'] == 0)
        {{ __('Level with the week before') }}
    @else
        <x-change :value="$week['change']">
            {{ $week['change'] > 0 ? '↑' : '↓' }}{{ abs($week['change']) }}%
        </x-change>
        {{ __('on the week before') }}
    @endif
</p>
