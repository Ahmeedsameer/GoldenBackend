<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * dompdf ships no Arabic-capable font by default (its bundled DejaVu Sans has
 * no Arabic glyphs, so Arabic text in exported PDFs rendered as blank boxes
 * even though dir="rtl" was already correct). This registers Tajawal
 * (SIL Open Font License — resources/fonts/OFL.txt) with dompdf's font
 * metrics so Blade views can just use `font-family: 'Tajawal'`.
 */
class ArabicPdfFont
{
    public const FAMILY = 'Tajawal';

    public static function apply(PdfWrapper $pdf): void
    {
        $metrics = $pdf->getDomPDF()->getFontMetrics();

        $metrics->registerFont(
            ['family' => self::FAMILY, 'style' => 'normal', 'weight' => 'normal'],
            resource_path('fonts/Tajawal-Regular.ttf'),
        );
        $metrics->registerFont(
            ['family' => self::FAMILY, 'style' => 'normal', 'weight' => 'bold'],
            resource_path('fonts/Tajawal-Bold.ttf'),
        );
    }
}
