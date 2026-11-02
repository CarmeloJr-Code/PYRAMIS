<?php

namespace App\Http\Controllers\Employee;

use App\Actions\BuildSummaryReport;
use App\Enums\SummaryPeriod;
use App\Enums\SummaryReport;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A workspace tab's summary for today, this week or this month, as a PDF.
 */
class SummaryReportController extends Controller
{
    /**
     * Render the summary, inline to read in the browser or as an attachment to
     * keep.
     *
     * One route serves every tab, so the tab's own gate is checked here rather
     * than as route middleware: the PDF is never wider than the screen it
     * summarises.
     */
    public function __invoke(Request $request, SummaryReport $report, SummaryPeriod $period, BuildSummaryReport $build): Response
    {
        if ($report->ability() !== null) {
            Gate::authorize($report->ability());
        }

        $range = $period->range();

        // Subset the font: embedding all of DejaVu Sans makes a one-page
        // summary close to a megabyte.
        $pdf = Pdf::setOption('isFontSubsettingEnabled', true)->loadView('pdf.summary-report', [
            'report' => $report,
            'period' => $period,
            'range' => $range,
            'summary' => $build->handle($report, $range, $request->user()),
            'preparedBy' => $request->user(),
        ]);

        $filename = sprintf('%s-summary-%s-%s.pdf', $report->value, $period->value, $range->from());

        $response = $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);

        // Takings, customers' names and rosters: not for a shared cache.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
