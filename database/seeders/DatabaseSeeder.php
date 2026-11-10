<?php

namespace Database\Seeders;

use App\Models\SptDokumen;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /** The demo is a 2026 presentation; its dates run from 62 days before to 39 days after "today", which must stay inside 2026. */
    public const DEMO_AWAL = '2026-03-15 10:00:00';

    public const DEMO_AKHIR = '2026-11-20 10:00:00';

    public function run(): void
    {
        // Reference rows only in production (E11); klasifikasi comes from its migration.
        $this->call([IrbanSeeder::class, ObjekSeeder::class, JenisPenugasanSeeder::class]);
        if (app()->isProduction()) {
            return;
        }

        // Outside the window the demo seeds as if it were the nearest edge, so every status exists whatever the real date.
        $jam = Carbon::getTestNow();
        $sekarang = CarbonImmutable::now('Asia/Jakarta');
        $awal = CarbonImmutable::parse(self::DEMO_AWAL, 'Asia/Jakarta');
        $akhir = CarbonImmutable::parse(self::DEMO_AKHIR, 'Asia/Jakarta');
        if ($sekarang->lt($awal) || $sekarang->gt($akhir)) {
            Carbon::setTestNow($sekarang->lt($awal) ? $awal : $akhir);
        }

        try {
            $this->call([
                UserSeeder::class, TimSeeder::class, IrbanWilayahSeeder::class, PkptSeeder::class, PkptIrbanIDemo2026Seeder::class,
                SptSeeder::class, RealisasiDemoSeeder::class, KomentarSeeder::class, NotifikasiSeeder::class, LandingSeeder::class,
                DemoPresentasiSeeder::class, TimDemoSeeder::class,
            ]);
        } finally {
            Carbon::setTestNow($jam);
        }

        $this->hapusPdfYatim();
    }

    /** migrate:fresh empties spt_dokumen but not the disk: drop spt/ files no dokumen row points at (non-production only, after seeding). */
    private function hapusPdfYatim(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $disk = Storage::disk('local');
        $dirujuk = SptDokumen::pluck('path')->flip();
        $disk->delete(collect($disk->allFiles('spt'))->reject(fn ($f) => isset($dirujuk[$f]))->all());
    }
}
