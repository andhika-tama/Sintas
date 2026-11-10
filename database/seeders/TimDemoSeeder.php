<?php

namespace Database\Seeders;

use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Pegawai;
use App\Models\Tim;
use Illuminate\Database\Seeder;

/**
 * Dev only, run after DemoPresentasiSeeder: each irban's komposisi for this year is topped up to six anggota and gets
 * "Tim 1" and "Tim 2", so a presentation can use any irban. Idempotent: existing anggota and teams are kept.
 */
class TimDemoSeeder extends Seeder
{
    private const ANGGOTA = 6;

    /** Extra pegawai per irban, taken only as far as the komposisi is short of ANGGOTA. */
    private const TAMBAHAN = [
        'Irban I' => ['Ahmad Rifai, S.E.', 'Novita Sari, S.Ak.', 'Bayu Pratama, S.H.'],
        'Irban II' => ['Eko Wibowo, S.E.', 'Ratna Dewi, S.Ak.', 'Hadi Kusuma, S.T.'],
        'Irban III' => ['Yusuf Effendi, S.H.', 'Dian Puspitasari, S.E.', 'Arif Rahman, S.Kom.'],
        'Irban IV' => ['Rahmat Hidayat, S.E.', 'Wulan Sari, S.Ak.', 'Irfan Maulana, S.T.'],
        'Irban Khusus' => ['Fitri Handayani, S.H.', 'Bambang Irawan, S.E.', 'Lina Oktaviani, S.Ak.'],
        'Sekretariat' => ['Hendri Kurniawan, S.E.', 'Maya Anggraini, S.Ak.', 'Andi Saputra, S.H.', 'Rizky Ramadhan, S.Kom.'],
    ];

    /** Team name => [index into the komposisi anggota (by pegawai id) => peran], in SPT order. */
    private const TIM = [
        'Tim 1' => [0 => 'supervisor_dalnis', 1 => 'ketua', 2 => 'anggota', 3 => 'anggota'],
        'Tim 2' => [0 => 'supervisor_dalnis', 4 => 'ketua', 5 => 'anggota', 3 => 'anggota'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $tahun = (int) now('Asia/Jakarta')->year;

        foreach (Irban::orderBy('id')->get() as $irban) {
            $komposisi = IrbanTahunan::where(['irban_id' => $irban->id, 'tahun' => $tahun])->first();
            if (! $komposisi) {
                continue;
            }
            $kurang = self::ANGGOTA - $komposisi->anggota()->count();
            if ($kurang > 0) {
                $komposisi->anggota()->attach(array_map(fn ($n) => Pegawai::factory()->create(['nama_bergelar' => $n, 'jabatan' => 'Auditor'])->id,
                    array_slice(self::TAMBAHAN[$irban->nama] ?? [], 0, $kurang)));
            }
            $anggota = $komposisi->anggota()->orderBy('pegawai.id')->pluck('pegawai.id')->all();
            if (count($anggota) < self::ANGGOTA) {
                continue;
            }

            foreach (self::TIM as $nama => $susunan) {
                if (Tim::where(['irban_id' => $irban->id, 'tahun' => $tahun, 'nama' => $nama])->exists()) {
                    continue;
                }
                $tim = Tim::create(['irban_id' => $irban->id, 'tahun' => $tahun, 'nama' => $nama]);
                $urutan = 0;
                foreach ($susunan as $i => $peran) {
                    $tim->anggota()->create(['pegawai_id' => $anggota[$i], 'peran_default' => $peran, 'urutan' => ++$urutan]);
                }
            }
        }
    }
}
