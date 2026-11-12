<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Exports\RekapSheet;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Tests\TestCase;

class ArrayExportTest extends TestCase
{
    public function test_formula_like_strings_are_stored_as_text_and_numbers_stay_numbers(): void
    {
        $nilai = ['=HYPERLINK("http://x","klik")', '+1+1', '-2+3', '@SUM(A1)', "\t=1"];
        Excel::store(new ArrayExport(['Kolom'], array_map(fn ($v) => [$v, 42], $nilai)), 'uji-array-export.xlsx', 'local');
        // The local disk is faked in TestCase: read the file where that disk put it.
        $sheet = IOFactory::load(Storage::disk('local')->path('uji-array-export.xlsx'))->getActiveSheet();

        foreach ($nilai as $i => $v) {
            $sel = $sheet->getCell('A'.($i + 2));
            $this->assertSame(DataType::TYPE_STRING, $sel->getDataType(), $v);
            $this->assertSame($v, $sel->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B'.($i + 2))->getDataType());
        }
    }

    public function test_heading_row_repeats_on_every_printed_page(): void
    {
        Excel::store(new ArrayExport(['Kolom'], [['a'], ['b']]), 'uji-judul-cetak.xlsx', 'local');

        $sheet = IOFactory::load(Storage::disk('local')->path('uji-judul-cetak.xlsx'))->getActiveSheet();

        $this->assertSame([1, 1], $sheet->getPageSetup()->getRowsToRepeatAtTop());
    }

    public function test_without_lebar_the_styling_is_unchanged(): void
    {
        Excel::store(new ArrayExport(['Kolom', 'Dua'], [['a', 'b']]), 'uji-polos.xlsx', 'local');
        $s = IOFactory::load(Storage::disk('local')->path('uji-polos.xlsx'))->getActiveSheet();

        $this->assertSame(Fill::FILL_NONE, $s->getStyle('A1')->getFill()->getFillType());
        $this->assertFalse($s->getColumnDimension('A')->getAutoSize());
        $this->assertSame(-1.0, (float) $s->getColumnDimension('A')->getWidth());
    }

    public function test_with_lebar_header_is_coloured_widths_set_and_bungkus_wraps(): void
    {
        Excel::store(new ArrayExport(['Baris', 'Pesan'], [[1, '=SUM(A1)']], [], ['A' => 10, 'B' => 80], ['B']), 'uji-lebar.xlsx', 'local');
        $s = IOFactory::load(Storage::disk('local')->path('uji-lebar.xlsx'))->getActiveSheet();

        $this->assertSame('FF'.RekapSheet::HEADER_ISI, $s->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertTrue($s->getStyle('B1')->getFont()->getBold());
        $this->assertSame('FFFFFFFF', $s->getStyle('B1')->getFont()->getColor()->getARGB());
        $this->assertEquals(10, $s->getColumnDimension('A')->getWidth());
        $this->assertEquals(80, $s->getColumnDimension('B')->getWidth());
        $this->assertTrue($s->getStyle('B2')->getAlignment()->getWrapText());
        $this->assertSame(Alignment::VERTICAL_TOP, $s->getStyle('B2')->getAlignment()->getVertical());
        $this->assertFalse($s->getStyle('A2')->getAlignment()->getWrapText());
        $this->assertSame(DataType::TYPE_STRING, $s->getCell('B2')->getDataType());
    }
}
