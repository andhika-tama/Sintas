<?php

namespace Database\Seeders;

use App\Models\Irban;
use Illuminate\Database\Seeder;

// Reference data: runs in production too (the six units and their number codes are fixed).
class IrbanSeeder extends Seeder
{
    /** Nama => kode nomor; the fifth is the Irban Khusus (INSPEKTORAT-IRSUS), the sixth the Sekretariat (INSPEKTORAT-V). */
    public const DAFTAR = [
        'Irban I' => 'INSPEKTORAT-I',
        'Irban II' => 'INSPEKTORAT-II',
        'Irban III' => 'INSPEKTORAT-III',
        'Irban IV' => 'INSPEKTORAT-IV',
        'Irban Khusus' => 'INSPEKTORAT-IRSUS',
        'Sekretariat' => 'INSPEKTORAT-V',
    ];

    public function run(): void
    {
        foreach (self::DAFTAR as $nama => $kode) {
            Irban::firstOrCreate(['nama' => $nama], ['kode_nomor' => $kode]);
        }
    }
}
