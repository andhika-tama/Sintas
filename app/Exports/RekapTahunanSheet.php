<?php

namespace App\Exports;

use App\Exports\Concerns\AmanFormula;
use App\Services\ColorRules;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
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

/**
 * One month of the Rekap Tahunan (spec 1.1.4 Task 4, the Bagian Umum stambook): row 1 title, row 2 header (lavender A–E, then a narrow
 * separator and the klasifikasi legend in their colours), then two rows per SPT: A–D merged vertically, Nama Tim on the first row and the
 * date ribbon in the klasifikasi colour on the second.
 */
class RekapTahunanSheet extends DefaultValueBinder implements FromArray, WithColumnWidths, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    use AmanFormula {
        bindValue as amanBindValue;
    }

    public const BARIS_HEADER = 2;

    public const KOSONG = 'Tidak ada SPT pada bulan ini.';

    public const KOLOM = ['No SPT', 'No SPPD', 'Tanggal Terbit', 'Dalam Rangka', 'Nama Tim'];

    public const LAVENDER = 'CCC0DA';

    public const BIRU = '0070C0';

    private const LEBAR = ['A' => 9, 'B' => 16, 'C' => 18, 'D' => 46, 'E' => 42, 'F' => 2];

    private const LEBAR_LEGENDA = 14;

    /**
     * @param  list<array{nomor: string, sppd: string, terbit: string, rangka: string, tim: string, pita: string, warna: ?string}>  $blok
     * @param  list<array{nama: string, warna: ?string}>  $legenda
     */
    public function __construct(private string $bulan, private int $tahun, private array $blok, private array $legenda) {}

    /** No SPT and No SPPD stay text ("0028" must not become 28); everything else goes through AmanFormula. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && in_array($cell->getColumn(), ['A', 'B'], true) && $cell->getRow() > self::BARIS_HEADER) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return $this->amanBindValue($cell, $value);
    }

    public function array(): array
    {
        $baris = [
            ["Rekap SPT Bulan {$this->bulan} {$this->tahun}"],
            [...self::KOLOM, null, ...array_column($this->legenda, 'nama')],
        ];
        if (! $this->blok) {
            $baris[] = [self::KOSONG];
        }
        foreach ($this->blok as $b) {
            $baris[] = [$b['nomor'], $b['sppd'], $b['terbit'], $b['rangka'], $b['tim']];
            $baris[] = [null, null, null, null, $b['pita']];
        }

        return $baris;
    }

    public function title(): string
    {
        return mb_strtoupper($this->bulan);
    }

    public function columnWidths(): array
    {
        $lebar = self::LEBAR;
        foreach (array_keys($this->legenda) as $i) {
            $lebar[Coordinate::stringFromColumnIndex(7 + $i)] = self::LEBAR_LEGENDA;
        }

        return $lebar;
    }

    /** Text on a klasifikasi colour: the same white/neutral-900 choice as the Admin preview. */
    private static function teks(string $warna): string
    {
        return ltrim(ColorRules::labelFor('#'.$warna) ?? ColorRules::DARK, '#');
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $e) {
            $s = $e->sheet->getDelegate();
            $h = self::BARIS_HEADER;
            $akhir = $h + max(1, 2 * count($this->blok));

            $s->mergeCells('A1:E1');
            $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $s->getRowDimension(1)->setRowHeight(24);
            $kepala = $s->getStyle("A{$h}:E{$h}");
            $kepala->getFont()->setBold(true);
            $kepala->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::LAVENDER);
            $kepala->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            foreach ($this->legenda as $i => $l) {
                $sel = $s->getStyle(Coordinate::stringFromColumnIndex(7 + $i).$h);
                $sel->getFont()->setBold(true);
                $sel->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
                $sel->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                if ($l['warna']) {
                    $sel->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($l['warna']);
                    $sel->getFont()->getColor()->setRGB(self::teks($l['warna']));
                }
            }
            $s->getRowDimension($h)->setRowHeight(30);

            if (! $this->blok) {
                $s->mergeCells('A3:E3');
                $s->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $s->getStyle('A3')->getFont()->setItalic(true);
            }
            foreach ($this->blok as $i => $b) {
                $r = $h + 1 + 2 * $i;
                foreach (['A', 'B', 'C', 'D'] as $k) {
                    $s->mergeCells("{$k}{$r}:{$k}".($r + 1));
                }
                // Styled over both rows of the merge, so every reader (Excel, LibreOffice, HTML) sees the same alignment.
                $s->getStyle("A{$r}:D".($r + 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                $s->getStyle("A{$r}:C".($r + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $s->getStyle("E{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                $pita = $s->getStyle('E'.($r + 1));
                $pita->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $pita->getFont()->setBold(true);
                if ($b['warna']) {
                    $pita->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($b['warna']);
                    $pita->getFont()->getColor()->setRGB(self::teks($b['warna']));
                }
                // Merged and wrapped cells do not auto-fit: size the name row to the longer of Nama Tim and Dalam Rangka.
                $baris = max(substr_count($b['tim'], "\n") + 1, (int) ceil(mb_strlen($b['rangka']) / (self::LEBAR['D'] - 4)) - 1, 1);
                $s->getRowDimension($r)->setRowHeight(15 * $baris + 3);
                $s->getRowDimension($r + 1)->setRowHeight(18);
            }

            $s->getStyle("A{$h}:E{$akhir}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $s->getStyle("E{$h}:E{$akhir}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB(self::BIRU);

            $s->freezePane('A'.($h + 1));
            $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($h, $h);
            $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
            $s->getPageSetup()->setFitToPage(true);
            // Any number of legend columns (6–8 klasifikasi included) stays on the printed page.
            $s->getPageSetup()->setPrintArea('A1:'.($this->legenda ? Coordinate::stringFromColumnIndex(6 + count($this->legenda)) : 'E').$akhir);
            $s->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        }];
    }
}
