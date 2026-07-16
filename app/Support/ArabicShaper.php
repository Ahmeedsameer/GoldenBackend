<?php

namespace App\Support;

use App\Models\CompanySetting;
use ArPHP\I18N\Arabic;

/**
 * dompdf has no Arabic text-shaping/bidi engine at all — its low-level PDF
 * writer (Cpdf::addText) draws glyphs strictly left-to-right in the exact
 * character order it's given, with no letter joining. Passing it raw
 * logical Arabic text therefore renders as disconnected, wrong-order
 * letterforms (confirmed by inspecting Cpdf.php: no bidi/reshape logic
 * exists anywhere in dompdf or its dependencies).
 *
 * The fix used everywhere in this app: pre-process every string that may
 * contain Arabic through ar-php's utf8Glyphs() BEFORE it reaches a Blade PDF
 * view. That function performs real Arabic contextual letter-joining and
 * reorders each line into visual (LTR-drawable) order — exactly what a
 * dumb left-to-right glyph renderer like dompdf needs. Pure-Latin/numeric
 * strings pass through unchanged, so this is safe to apply universally.
 */
class ArabicShaper
{
    private static ?Arabic $engine = null;

    private static function engine(): Arabic
    {
        return self::$engine ??= new Arabic();
    }

    /** Shape a single string. $hindo=false keeps Western digits (0-9) instead of ٠-٩. */
    public static function shape(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return self::engine()->utf8Glyphs($text, 200, false);
    }

    /**
     * Recursively shape every string in an array/scalar tree — used to
     * preprocess a whole $report (title/columns/rows) or $roster structure
     * in one call before handing it to a PDF view.
     */
    public static function shapeDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::shape($value);
        }

        if (is_array($value)) {
            return array_map([self::class, 'shapeDeep'], $value);
        }

        return $value;
    }

    /**
     * dompdf lays out <table> columns strictly left-to-right no matter what
     * `direction`/`dir` say — it reorders text runs for bidi but never
     * reorders table columns. For an RTL report this means the logically
     * first column (e.g. "الموظف") renders on the LEFT instead of the right.
     * The standard workaround: reverse the column order in the markup
     * itself so LTR placement produces the correct RTL visual order. Only
     * ever apply this to a PDF-only copy — never to $report used for
     * CSV/JSON, which must keep the natural logical column order.
     */
    public static function reverseTableColumns(array $report): array
    {
        $report['columns'] = array_reverse($report['columns']);
        $report['rows'] = array_map(fn ($row) => array_reverse($row), $report['rows']);

        return $report;
    }

    /**
     * Same dompdf table-column limitation as reverseTableColumns(), applied
     * to the weekly schedule roster: reverses the day columns so, combined
     * with emitting the employee-name column last in the Blade template
     * (see hr/schedule.blade.php), the visual result reads correctly
     * right-to-left (employee name rightmost, days progressing leftward).
     */
    public static function reverseRosterDays(array $roster): array
    {
        $roster['days'] = array_reverse($roster['days']);
        $roster['employees'] = array_map(function ($emp) {
            $emp['cells'] = array_reverse($emp['cells']);
            return $emp;
        }, $roster['employees']);

        return $roster;
    }

    /**
     * Builds the display object used by the pdf-letterhead/pdf-footer
     * partials: the name is shaped on its own (it stands alone in the
     * letterhead), while address/phone/email/website are joined and shaped
     * as ONE line so mixed Arabic+Latin content gets a single, correct bidi
     * pass rather than each fragment being reordered independently and then
     * concatenated (which would produce the wrong overall reading order).
     */
    public static function companyDisplay(CompanySetting $company): object
    {
        $contactParts = array_filter([
            $company->address,
            $company->phone,
            $company->email,
            $company->website,
        ]);

        return (object) [
            'name' => self::shape($company->name),
            'contactLine' => $contactParts ? self::shape(implode(' · ', $contactParts)) : null,
        ];
    }
}
