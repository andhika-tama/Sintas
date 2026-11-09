<?php

namespace Tests\Feature\Pkpt;

use App\Models\Irban;
use App\Models\IrbanWilayah;
use App\Models\Objek;
use App\Models\PerubahanPenugasan;
use App\Models\PkptItem;
use App\Services\KalenderPkpt;
use App\Services\WilayahPengawasan;
use Database\Seeders\IrbanSeeder;
use Database\Seeders\JenisPenugasanSeeder;
use Database\Seeders\PkptIrbanIDemo2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\JadwalItem;
use Tests\TestCase;

class KalenderPkptTest extends TestCase
{
    use RefreshDatabase;

    /** Demo table rows 1–25: jenis => [bulan, minggu] pairs. */
    private const MINGGU = [
        ['Evaluasi Risk Register', [[1, 2], [1, 3]]],
        ['Evaluasi RB dan Reviu DAK', [[1, 4]]],
        ['Pengawasan Dana BOS', [[2, 1], [2, 2], [2, 3], [2, 4]]],
        ['Reviu LKjIP', [[3, 1]]],
        ['Reviu DBH', [[3, 2]]],
        ['Reviu Tata Kelola Pajak', [[3, 4]]],
        ['Evaluasi RB dan Reviu DAK', [[4, 1]]],
        ['Evaluasi SAKIP PD', [[4, 2]]],
        ['Pengawasan Tata Kelola Desa/Keuangan Desa', [[4, 3], [4, 4], [4, 5], [5, 1]]],
        ['Pendampingan dan Evaluasi ZI PD', [[5, 2], [5, 3]]],
        ['Audit Kinerja Program Strategis Daerah', [[5, 4], [6, 1]]],
        ['Audit Ketaatan Puskesmas', [[6, 2], [6, 3], [6, 4]]],
        ['Evaluasi RB dan Reviu DAK', [[7, 1]]],
        ['Reviu Kinerja Pelayanan Publik Sektor Kependudukan dan Pencatatan Sipil', [[7, 2], [7, 3]]],
        ['Monev Program Strategis Nasional Ketahanan Pangan dan Pembangunan Tiga Juta Rumah', [[7, 4]]],
        ['Audit Ketaatan Kecamatan', [[7, 5], [8, 1], [8, 2]]],
        ['Audit Kinerja Program Prioritas Daerah', [[8, 3], [8, 4]]],
        ['Audit Ketaatan OPD', [[9, 1], [9, 2], [9, 3], [9, 4]]],
        ['Evaluasi Pelayanan Publik Kemenpan RB', [[9, 5]]],
        ['Evaluasi Pelayanan Publik Ombudsman', [[10, 1]]],
        ['Audit Tujuan Tertentu Perusda/BUMD', [[10, 2]]],
        ['Pengawasan Tata Kelola Desa/Keuangan Desa', [[10, 3], [10, 4], [11, 1], [11, 2]]],
        ['Evaluasi Risk Register', [[11, 3], [11, 4]]],
        ['Evaluasi RB dan Reviu DAK', [[12, 1]]],
        ['Monev', [[12, 2], [12, 3], [12, 4]]],
    ];

    private Irban $irban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([IrbanSeeder::class, JenisPenugasanSeeder::class, PkptIrbanIDemo2026Seeder::class]);
        $this->irban = Irban::where('nama', 'Irban I')->first();
    }

    /** @return array<string, list<string>> "bulan-minggu" => sorted jenis names, non-empty cells only */
    private function peta(array $kalender): array
    {
        $peta = [];
        foreach ($kalender as $b) {
            foreach ($b['minggu'] as $n => $m) {
                if ($m['item']) {
                    $peta[$b['bulan'].'-'.$n] = collect($m['item'])->pluck('jenis.nama')->sort()->values()->all();
                }
            }
        }

        return $peta;
    }

    public function test_weeks_match_the_demo_table_and_nothing_else(): void
    {
        $harapan = [];
        foreach (self::MINGGU as [$nama, $minggu]) {
            foreach ($minggu as [$b, $n]) {
                $harapan[$b.'-'.$n][] = $nama;
            }
        }
        $harapan = array_map(fn ($v) => collect($v)->sort()->values()->all(), $harapan);

        $this->assertEquals($harapan, $this->peta(app(KalenderPkpt::class)->untuk(2026, $this->irban->id)));
    }

    public function test_shape_week_five_and_current_week(): void
    {
        $this->travelTo('2026-03-18 10:00:00'); // Wed → Mar M3
        $k = app(KalenderPkpt::class)->untuk(2026, $this->irban->id);

        $this->assertCount(12, $k);
        $this->assertSame('Januari', $k[0]['nama']);
        $this->assertSame([1, 2, 3, 4, 5], array_keys($k[0]['minggu']));
        $w5 = $k[0]['minggu'][5];
        $this->assertSame([false, null, null, []], [$w5['ada'], $w5['mulai'], $w5['selesai'], $w5['item']]);
        $this->assertSame(['2026-01-05', '2026-01-09'], [$k[0]['minggu'][1]['mulai']->toDateString(), $k[0]['minggu'][1]['selesai']->toDateString()]);

        $ini = [];
        foreach ($k as $b) {
            foreach ($b['minggu'] as $n => $m) {
                $m['ini'] && $ini[] = $b['bulan'].'-'.$n;
            }
        }
        $this->assertSame(['3-3'], $ini);

        $item = $k[0]['minggu'][2]['item'][0];
        $this->assertSame(['id', 'jenis', 'keterangan', 'mulai', 'selesai', 'semula', 'direvisi', 'tambahan_ke', 'selesai_spt', 'target'], array_keys($item));
        $this->assertSame(['id', 'nama', 'warna', 'warna_teks'], array_keys($item['jenis']));
        $this->assertSame([null, false, 0, 1], [$item['semula'], $item['direvisi'], $item['selesai_spt'], $item['target']]);
    }

    public function test_no_current_week_outside_the_year(): void
    {
        $this->travelTo('2027-02-03 10:00:00');
        $k = app(KalenderPkpt::class)->untuk(2026, $this->irban->id);
        $this->assertSame([], array_filter(array_merge(...array_map(fn ($b) => array_column($b['minggu'], 'ini'), $k))));
    }

    public function test_moved_item_shows_semula_and_perubahan_penugasan_changes_nothing(): void
    {
        $item = PkptItem::where('irban_id', $this->irban->id)->whereDate('tanggal_mulai', '2026-06-29')->first(); // Jul M1
        JadwalItem::geser($item, '2026-11-02', '2026-11-06');
        $lain = PkptItem::where('irban_id', $this->irban->id)->whereDate('tanggal_mulai', '2026-10-05')->first(); // Okt M1
        PerubahanPenugasan::factory()->create(['pkpt_item_id' => $lain->id, 'tanggal_mulai' => '2026-12-01', 'tanggal_selesai' => '2026-12-02']);

        $k = app(KalenderPkpt::class)->untuk(2026, $this->irban->id);

        $this->assertSame([], $k[6]['minggu'][1]['item']);
        $baru = collect($k[10]['minggu'][1]['item'])->firstWhere('id', $item->id);
        $this->assertSame([true, '2026-06-29', '2026-11-02'], [$baru['direvisi'], $baru['semula'][0]->toDateString(), $baru['mulai']->toDateString()]);
        $tetap = $k[9]['minggu'][1]['item'][0];
        $this->assertSame([$lain->id, false, null], [$tetap['id'], $tetap['direvisi'], $tetap['semula']]);
    }

    public function test_other_irban_items_never_appear_and_query_count_is_constant(): void
    {
        $lain = Irban::where('id', '!=', $this->irban->id)->first();
        $this->assertSame([], $this->peta(app(KalenderPkpt::class)->untuk(2026, $lain->id)));
        $this->assertSame([], $this->peta(app(KalenderPkpt::class)->untuk(2027, $this->irban->id)));

        DB::enableQueryLog();
        app(KalenderPkpt::class)->untuk(2026, $this->irban->id);
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    public function test_wilayah_scoped_to_one_irban(): void
    {
        $lain = Irban::where('id', '!=', $this->irban->id)->first();
        $kec = Objek::factory()->create(['nama' => 'Kec Z', 'jenis' => 'kecamatan']);
        $rsud = Objek::factory()->create(['nama' => 'RSUD R', 'jenis' => 'rsud']);
        $bumd = Objek::factory()->create(['nama' => 'BUMD M', 'jenis' => 'bumd']);
        foreach ([$kec, $rsud, $bumd] as $o) {
            IrbanWilayah::create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'objek_id' => $o->id]);
        }
        IrbanWilayah::create(['irban_id' => $lain->id, 'tahun' => 2026, 'objek_id' => Objek::factory()->create(['nama' => 'Kec Lain', 'jenis' => 'kecamatan'])->id]);

        $this->assertSame([['irban_id' => $this->irban->id, 'irban' => 'Irban I', 'kecamatan' => ['Kec Z'], 'dinas_badan' => [], 'bumd' => ['BUMD M'], 'rsud' => ['RSUD R']]],
            app(WilayahPengawasan::class)->untuk(2026, $this->irban->id));
        $this->assertCount(Irban::count(), app(WilayahPengawasan::class)->untuk(2026));
    }
}
