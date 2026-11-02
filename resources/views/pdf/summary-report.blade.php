{{--
    A workspace tab's summary, printed by dompdf.

    dompdf reads CSS 2.1 and no Tailwind, so this page carries its own plain
    styles and lays the headline figures out as a table rather than a grid.
    DejaVu Sans is bundled with dompdf and has the peso sign.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }} — {{ $range->label() }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #27272a; }
        .brand { color: #6b21a8; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; }
        h1 { font-size: 18px; margin: 4px 0 2px; }
        .meta { color: #71717a; font-size: 9px; margin-bottom: 16px; }
        .metrics { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 18px; }
        .metrics td { border: 1px solid #e4e4e7; border-radius: 4px; padding: 8px 10px; vertical-align: top; }
        .metrics .label { color: #71717a; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .metrics .value { font-size: 14px; font-weight: bold; margin-top: 3px; }
        h2 { font-size: 12px; margin: 16px 0 6px; color: #3b0764; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f4f4f5; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; color: #52525b; padding: 5px 6px; border-bottom: 1px solid #d4d4d8; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #f4f4f5; }
        table.data tr { page-break-inside: avoid; }
        .empty { color: #71717a; font-style: italic; }
        .footer { position: fixed; bottom: -16px; left: 0; right: 0; color: #a1a1aa; font-size: 8px; }
    </style>
</head>
<body>
    <div class="footer">{{ config('app.name') }} · {{ __('Prepared by :name on :date', ['name' => $preparedBy->name, 'date' => now()->format('d M Y g:i A')]) }}</div>

    <div class="brand">Purple Yam Malaybalay · PYRAMIS</div>
    <h1>{{ $report->title() }}</h1>
    <div class="meta">{{ $period->label() }} · {{ $range->label() }}</div>

    @foreach (array_chunk($summary['metrics'], 4) as $row)
        <table class="metrics">
            <tr>
                @foreach ($row as $metric)
                    <td style="width: 25%">
                        <div class="label">{{ $metric['label'] }}</div>
                        <div class="value">{{ $metric['value'] }}</div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < 4; $i++)
                    <td style="width: 25%; border: 0"></td>
                @endfor
            </tr>
        </table>
    @endforeach

    @if ($summary['metrics'] === [] && $summary['tables'] === [])
        <p class="empty">{{ __('Nothing to summarise for your role.') }}</p>
    @endif

    @foreach ($summary['tables'] as $table)
        <h2>{{ $table['heading'] }}</h2>

        @if ($table['rows'] === [])
            <p class="empty">{{ $table['empty'] }}</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($table['columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table['rows'] as $cells)
                        <tr>
                            @foreach ($cells as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
