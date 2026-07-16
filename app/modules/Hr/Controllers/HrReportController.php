<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\HrReportService;
use App\Support\ArabicPdfFont;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrReportController extends Controller
{
    public function __construct(private HrReportService $reports) {}

    /** GET /api/hr/reports/{type}?from=&to=&year=&month=&shop_id=&status= */
    public function data(Request $request, string $type)
    {
        return response()->json(['message' => 'ok', 'data' => $this->reports->build($type, $request->all())]);
    }

    /** GET /api/hr/reports/{type}/export?format=csv|pdf&... */
    public function export(Request $request, string $type)
    {
        $report = $this->reports->build($type, $request->all());
        $format = $request->get('format', 'csv');
        $file   = 'hr-' . str_replace('_', '-', $type) . '-' . now()->format('Ymd-His');

        return $format === 'pdf'
            ? $this->pdf($report, $file)
            : $this->csv($report, $file);
    }

    /** UTF-8 (BOM) CSV — opens cleanly in Excel, including Arabic. */
    private function csv(array $report, string $file): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [$report['title']]);
            fputcsv($out, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$file}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(array $report, string $file)
    {
        $pdf = Pdf::loadView('hr.report', ['report' => $report])->setPaper('a4', 'landscape');
        ArabicPdfFont::apply($pdf);

        return $pdf->download("{$file}.pdf");
    }
}
