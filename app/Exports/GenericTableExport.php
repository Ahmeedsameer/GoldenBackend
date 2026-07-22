<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The single reusable Excel export every report uses — never build a
 * report-specific export class. Give it the same title/columns/rows a PDF
 * export gets and it produces a matching, properly formatted .xlsx (bold
 * headers, auto-sized columns, right-to-left, currency columns formatted as
 * numbers, filters shown as a note row above the table).
 */
class GenericTableExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize, WithTitle, WithColumnFormatting, WithEvents
{
    /**
     * @param string[] $columns
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, string> $filtersApplied
     * @param int[] $currencyColumns Zero-based column indexes to format as currency numbers.
     */
    public function __construct(
        private string $title,
        private array $columns,
        private array $rows,
        private array $filtersApplied = [],
        private array $currencyColumns = [],
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->columns;
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/', '', $this->title), 0, 31) ?: 'Report';
    }

    public function columnFormats(): array
    {
        // Range must be bounded to the actual row count — an unbounded range
        // (e.g. 2:100000) forces PhpSpreadsheet to materialize a style for
        // every one of those rows and turns a sub-second export into a
        // multi-second one even for a handful of real rows.
        $lastRow = count($this->rows) + 1;
        $formats = [];
        foreach ($this->currencyColumns as $index) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $formats[$letter . '2:' . $letter . $lastRow] = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1;
        }

        return $formats;
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setRightToLeft(true);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Title row above the header, spanning the table width — mirrors the PDF's own title.
                $sheet->insertNewRowBefore(1, 1);
                $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($this->columns));
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->setCellValue('A1', $this->title);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

                // Applied filters, if any, as a second note row.
                if (! empty($this->filtersApplied)) {
                    $sheet->insertNewRowBefore(2, 1);
                    $sheet->mergeCells("A2:{$lastCol}2");
                    $filterText = collect($this->filtersApplied)
                        ->filter(fn ($v) => $v !== null && $v !== '')
                        ->map(fn ($v, $k) => "{$k}: {$v}")
                        ->implode(' · ');
                    $sheet->setCellValue('A2', $filterText);
                    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
                }
            },
        ];
    }
}
