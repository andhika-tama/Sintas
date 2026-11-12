<?php

namespace Tests\Feature\Impor;

use App\Exports\RekapSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Reads back the xlsx a Livewire importer page sent as a download (1.2.1 Task 2). */
trait PeriksaUnduhanExcel
{
    protected function bacaUnduhan($komponen): Worksheet
    {
        $dasar = tempnam(sys_get_temp_dir(), 'unduh');
        $path = $dasar.'.xlsx';
        $this->beforeApplicationDestroyed(fn () => array_map(fn ($f) => @unlink($f), [$dasar, $path]));
        file_put_contents($path, base64_decode($komponen->effects['download']['content']));

        return IOFactory::load($path)->getActiveSheet();
    }

    protected function periksaHeaderMint(Worksheet $s, int $kolom): void
    {
        for ($i = 1; $i <= $kolom; $i++) {
            $sel = $s->getCell([$i, 1]);
            $this->assertSame('FF'.RekapSheet::HEADER_ISI, $s->getStyle($sel->getCoordinate())->getFill()->getStartColor()->getARGB());
            $this->assertTrue($s->getStyle($sel->getCoordinate())->getFont()->getBold());
            $this->assertSame('FFFFFFFF', $s->getStyle($sel->getCoordinate())->getFont()->getColor()->getARGB());
        }
    }

    /** @param  array<string, int>  $lebar */
    protected function periksaLebar(Worksheet $s, array $lebar): void
    {
        foreach ($lebar as $huruf => $w) {
            $this->assertEquals($w, $s->getColumnDimension($huruf)->getWidth(), $huruf);
        }
    }

    protected function periksaBerkasGalat(Worksheet $s): void
    {
        $this->periksaHeaderMint($s, 3);
        $this->periksaLebar($s, ['A' => 10, 'B' => 22, 'C' => 80]);
        $this->assertTrue($s->getStyle('C2')->getAlignment()->getWrapText());
        $this->assertSame(DataType::TYPE_STRING, $s->getCell('C2')->getDataType()); // Pesan is text, never a formula
        $this->assertNotSame('', (string) $s->getCell('C2')->getValue());
    }
}
