<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * dompdf's raw canvas text API (page_text()/Cpdf::addText()) draws glyphs
 * left-to-right in the exact order given, with NO bidi reordering and NO
 * Arabic contextual shaping — it's a low-level PDF content-stream call, not
 * the CSS/HTML text renderer. Passing it Arabic words therefore renders them
 * broken (disconnected letters, wrong direction). dompdf's own HTML/CSS
 * layout engine (used for the rest of every PDF, including the letterhead
 * partial) DOES apply bidi + shaping correctly — so Arabic content must
 * always go through Blade/HTML, never through this canvas API.
 *
 * This helper therefore draws ONLY digits and a slash ("3 / 12") — content
 * with no directionality to get wrong — positioned in a page corner. The
 * company name/contact line is rendered separately via the pdf-footer Blade
 * partial (plain HTML, RTL-correct).
 */
class PdfPageFooter
{
    public static function apply(PdfWrapper $pdf): void
    {
        $canvas = $pdf->getDomPDF()->getCanvas();
        $fontMetrics = $pdf->getDomPDF()->getFontMetrics();
        $font = $fontMetrics->getFont(ArabicPdfFont::FAMILY, 'normal');

        $canvas->page_text(
            $canvas->get_width() - 60,
            $canvas->get_height() - 22,
            "{PAGE_NUM} / {PAGE_COUNT}",
            $font,
            8,
            [0.4, 0.4, 0.4]
        );
    }
}
