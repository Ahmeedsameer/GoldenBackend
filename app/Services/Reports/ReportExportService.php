<?php

namespace App\Services\Reports;

use App\Models\CompanySetting;
use App\Support\ArabicPdfFont;
use App\Support\ArabicShaper;
use App\Support\PdfPageFooter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single reusable export engine every report in the ERP goes through —
 * no report writes its own PDF/Excel logic. Callers only ever hand over
 * already-computed data (title + columns + rows, exactly what's on screen);
 * this service never re-derives business data itself, so it can never
 * diverge from what a report actually shows.
 *
 * The PDF path reuses the exact Arabic-shaping + branding + page-footer
 * pipeline already proven correct for HR reports (see
 * App\Support\ArabicShaper, App\Support\PdfPageFooter,
 * App\Support\ArabicPdfFont, resources/views/hr/report.blade.php) — dompdf's
 * raw canvas API has no bidi/shaping support, so every report needs the
 * same treatment, and this is the one place that applies it.
 */
class ReportExportService
{
    /**
     * @param string $title Report title, shown as the PDF/Excel heading.
     * @param string[] $columns Column headers, in display order.
     * @param array<int, array<int, string|int|float|null>> $rows Positional cells matching $columns.
     * @param array<string, string> $filtersApplied Label => value, shown in the meta line (e.g. ['الفرع' => 'الفرع الرئيسي']).
     */
    public function pdf(string $title, array $columns, array $rows, array $filtersApplied = [], string $orientation = 'landscape')
    {
        $company = CompanySetting::current();
        $companyDisplay = ArabicShaper::companyDisplay($company);

        $report = ArabicShaper::reverseTableColumns(ArabicShaper::shapeDeep([
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
        ]));

        $metaLine = ArabicShaper::shape($this->buildMetaLine($company->name, $filtersApplied));

        $pdf = Pdf::loadView('hr.report', [
            'report' => $report,
            'company' => $company,
            'companyDisplay' => $companyDisplay,
            'metaLine' => $metaLine,
        ])->setPaper('a4', $orientation);

        ArabicPdfFont::apply($pdf);
        PdfPageFooter::apply($pdf);

        return $pdf->download($this->fileName($title) . '.pdf');
    }

    /** @param array<string, string> $filtersApplied */
    public function excel(string $title, array $columns, array $rows, array $filtersApplied = [], array $currencyColumns = [])
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\GenericTableExport($title, $columns, $rows, $filtersApplied, $currencyColumns),
            $this->fileName($title) . '.xlsx',
        );
    }

    /** UTF-8 (BOM) CSV fallback — kept for parity with the existing HR export, not the primary "Excel" path. */
    public function csv(string $title, array $columns, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$title]);
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $this->fileName($title) . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildMetaLine(string $companyName, array $filtersApplied): string
    {
        $user = Auth::user();
        $parts = [
            $companyName,
            'بواسطة: ' . ($user->name ?? '—'),
            now()->format('Y-m-d H:i'),
        ];
        foreach ($filtersApplied as $label => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = "{$label}: {$value}";
            }
        }

        return implode(' · ', $parts);
    }

    private function fileName(string $title): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $title) ?: 'report';

        return trim($slug, '-') . '-' . now()->format('Ymd-His');
    }
}
