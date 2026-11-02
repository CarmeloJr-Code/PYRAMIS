{{--
    The "Summary PDF" menu every operational tab carries.

    Plain links rather than Livewire actions: the PDF is a file, so it opens in
    a new tab to read or comes down as an attachment to keep. What goes in it,
    and who may have it, is App\Http\Controllers\Employee\SummaryReportController's
    business.
--}}
@props(['report'])

<flux:dropdown position="bottom" align="end" {{ $attributes }}>
    <flux:button icon="document-text" icon:trailing="chevron-down" data-test="summary-report-button">
        {{ __('Summary PDF') }}
    </flux:button>

    <flux:menu>
        @foreach (\App\Enums\SummaryPeriod::cases() as $period)
            <flux:menu.group :heading="__($period->label())">
                <flux:menu.item
                    icon="eye"
                    :href="route('employee.summaries.show', ['report' => $report, 'period' => $period])"
                    target="_blank"
                >
                    {{ __('View') }}
                </flux:menu.item>

                <flux:menu.item
                    icon="arrow-down-tray"
                    :href="route('employee.summaries.show', ['report' => $report, 'period' => $period, 'download' => 1])"
                >
                    {{ __('Download') }}
                </flux:menu.item>
            </flux:menu.group>
        @endforeach
    </flux:menu>
</flux:dropdown>
