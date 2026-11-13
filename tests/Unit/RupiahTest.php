<?php

namespace Tests\Unit;

use App\Support\Rupiah;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Versi 1.3 Task 3: one Rupiah format for screens/PDF and one parser shared (in rules) with the x-ui.input-rupiah component. */
class RupiahTest extends TestCase
{
    public function test_format(): void
    {
        $this->assertSame('Rp600.000', Rupiah::format(600000));
        $this->assertSame('Rp0', Rupiah::format(0));
        $this->assertSame('–', Rupiah::format(null));
        $this->assertSame('Rp999.999.999.999', Rupiah::format(999999999999));
        $this->assertSame('Rp1.000', Rupiah::format(1000));
    }

    #[DataProvider('sah')]
    public function test_baca_menerima(mixed $masuk, ?int $hasil): void
    {
        $this->assertSame($hasil, Rupiah::baca($masuk));
    }

    public static function sah(): array
    {
        return [
            'digit' => ['600000', 600000],
            'int' => [600000, 600000],
            'terformat' => ['Rp600.000', 600000],
            'spasi' => ['Rp 600.000', 600000],
            'desimal' => ['600.000,00', 600000],
            'desimal dibuang' => ['1.000.000,50', 1000000],
            'tempel lengkap' => [' Rp 1.000.000,50 ', 1000000],
            'rp kecil dan titik' => ['rp. 25.000', 25000],
            'nol' => ['0', 0],
            'nol di depan' => ['0075', 75],
            'batas' => ['999.999.999.999', 999999999999],
            'kosong' => ['', null],
            'null' => [null, null],
            'spasi saja' => ['   ', null],
            'rp saja' => ['Rp', null],
        ];
    }

    #[DataProvider('ditolak')]
    public function test_baca_menolak(mixed $masuk, string $pesan): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($pesan);
        Rupiah::baca($masuk);
    }

    public static function ditolak(): array
    {
        $format = 'Realisasi dana harus berupa angka Rupiah, tidak boleh minus.';
        $batas = 'Realisasi dana maksimal Rp999.999.999.999.';

        return [
            'minus' => ['-5', $format],
            'minus int' => [-5, $format],
            'eksponen' => ['1e9', $format],
            'huruf' => ['12a', $format],
            'desimal huruf' => ['12,5a', $format],
            'float' => [1.5, $format],
            'array' => [['1'], $format],
            'bool' => [true, $format],
            '13 digit' => ['1000000000000', $batas],
            '13 digit terformat' => ['Rp1.000.000.000.000', $batas],
            'desimal inggris' => ['600000.00', $format],
            'satu titik' => ['1.5', $format],
            'dua titik' => ['1.2.3', $format],
            'kelompok empat' => ['1.0000', $format],
            'sangat panjang' => [str_repeat('9', 40), $batas],
        ];
    }
}
