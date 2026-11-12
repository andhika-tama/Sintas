<?php

namespace App\Exports;

use App\Exports\Concerns\AmanFormula;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Headings + rows; $textColumns (e.g. ['B']) are formatted as text so long numbers like NIP survive. Strings that could be read as formulas are always stored as text.
 * Strict null comparison: a 0 is written as 0, not as an empty cell (rekap counts).
 * Optional $lebar (['A' => 10, ...]) styles the sheet like the other downloads (mint header, borders, widths); $bungkus lists the letters of wrapped, top-aligned columns. null = plain sheet.
 */
class ArrayExport extends DefaultValueBinder implements FromArray, WithColumnFormatting, WithColumnWidths, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison
{
    use AmanFormula;

    public function __construct(private array $headings, private array $rows, private array $textColumns = [], private ?array $lebar = null, private array $bungkus = []) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function columnWidths(): array
    {
        return $this->lebar ?? [];
    }

    /** The heading row repeats on every printed page. */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $e) {
            $s = $e->sheet->getDelegate();
            $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
            if ($this->lebar === null) {
                return;
            }
            $z = Coordinate::stringFromColumnIndex(max(count($this->headings), 1));
            $akhir = max($s->getHighestRow(), 1);
            $s->getStyle("A1:{$z}{$akhir}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(RekapSheet::GARIS);
            $s->getStyle("A1:{$z}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(RekapSheet::HEADER_ISI);
            $s->getStyle("A1:{$z}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $s->getStyle("A1:{$z}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            foreach ($this->bungkus as $k) {
                $s->getStyle("{$k}2:{$k}".max($akhir, 2))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            }
        }];
    }

    public function columnFormats(): array
    {
        return array_fill_keys($this->textColumns, NumberFormat::FORMAT_TEXT);
    }
}
