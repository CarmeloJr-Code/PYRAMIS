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
        <span class="{{ $week['change'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
            {{ $week['change'] > 0 ? '↑' : '↓' }}{{ abs($week['change']) }}%
        </span>
        {{ __('on the week before') }}
    @endif
</p>
