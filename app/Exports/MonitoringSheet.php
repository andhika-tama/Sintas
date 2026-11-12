<?php

namespace App\Exports;

use App\Exports\Concerns\AmanFormula;
use App\Services\Ekspor\PembangunMonitoring;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Monitoring SPT as one styled sheet (1.2.1 Task 2, modelled on RegisterSheet): rows 1-3 title, filter summary and "Dibuat",
 * row 4 the mint header, then one row per SPT. Colours and widths are read from RekapSheet, which stays untouched.
 */
class MonitoringSheet extends DefaultValueBinder implements FromArray, WithColumnWidths, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithStyles, WithTitle
{
    use AmanFormula {
        bindValue as amanBindValue;
    }

    public const BARIS_HEADER = 4;

    /** Width type per PembangunMonitoring::KOLOM column (RekapSheet::LEBAR keys). */
    private const TIPE = ['nomor', 'teks', 'panjang', 'tanggal', 'tanggal', 'teks', 'teks', 'panjang'];

    /** Wrapped in the sheet; every body column that holds free text or a multi-line list. */
    private const BUNGKUS = ['teks', 'tanggal', 'panjang'];

    /**
     * @param  list<string>  $filter  PembangunMonitoring::ringkasan() (via export())
     * @param  list<list<?string>>  $baris  PembangunMonitoring::baris()
     */
    public function __construct(private int $tahun, private array $filter, private array $baris, private string $dibuat) {}

    public function array(): array
    {
        return [
            ["Monitoring SPT {$this->tahun}"],
            ['Filter: '.($this->filter ? implode('; ', $this->filter) : 'Semua data')],
            ['Dibuat: '.$this->dibuat.' · '.count($this->baris).' SPT'],
            PembangunMonitoring::KOLOM,
            ...$this->baris,
        ];
    }

    /** Nomor SPT (A) stays text: "1e5" or "123" must not become a number. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $cell->getColumn() === 'A' && $cell->getRow() > self::BARIS_HEADER) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return $this->amanBindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Monitoring SPT';
    }

    public function columnWidths(): array
    {
        $lebar = [];
        foreach (self::TIPE as $i => $tipe) {
            $lebar[Coordinate::stringFromColumnIndex($i + 1)] = RekapSheet::LEBAR[$tipe];
        }

        return $lebar;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['bold' => true]],
            self::BARIS_HEADER => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
        ];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $e) {
            $s = $e->sheet->getDelegate();
            $h = self::BARIS_HEADER;
            $akhir = $h + count($this->baris);
            $z = Coordinate::stringFromColumnIndex(count(self::TIPE));

            $s->getStyle("A{$h}:{$z}{$akhir}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(RekapSheet::GARIS);
            $s->getStyle("A{$h}:{$z}{$h}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(RekapSheet::HEADER_ISI);
            $s->getStyle("A{$h}:{$z}{$h}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            foreach (self::TIPE as $i => $tipe) {
                $k = Coordinate::stringFromColumnIndex($i + 1);
                $s->getStyle($k.($h + 1).':'.$k.max($akhir, $h + 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(in_array($tipe, self::BUNGKUS, true));
            }
            $s->setAutoFilter("A{$h}:{$z}{$akhir}");
            $s->freezePane('B'.($h + 1)); // header row and the Nomor SPT column stay in view
            $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($h, $h);
            $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
            $s->getPageSetup()->setFitToPage(true);
        }];
    }
}
