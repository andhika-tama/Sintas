<?php

namespace Tests\Feature\Rekap;

use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Services\RealisasiItemPkpt;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\JadwalItem;
use Tests\TestCase;

class RealisasiItemPkptTest extends TestCase
{
    use RefreshDatabase;

    private Irban $a;

    private Irban $b;

    private JenisPenugasan $j;

    private Pkpt $pkpt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00', 'Asia/Jakarta'));
        app(Settings::class)->set('rekap.dasar_triwulan', 'tanggal_selesai');
        $this->a = Irban::factory()->create(['nama' => 'Irban A']);
        $this->b = Irban::factory()->create(['nama' => 'Irban B']);
        $this->j = JenisPenugasan::factory()->create();
        $this->pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
    }

    private function item(Irban $i, string $m, string $s, ?string $ket = null): PkptItem
    {
        return PkptItem::factory()->create(['pkpt_id' => $this->pkpt->id, 'irban_id' => $i->id, 'jenis_penugasan_id' => $this->j->id,
            'tanggal_mulai' => $m, 'tanggal_selesai' => $s, 'keterangan' => $ket]);
    }

    private function spt(string $state, Irban $i, ?PkptItem $it, string $m, string $s): Spt
    {
        $f = Spt::factory();

        return ($state === 'draf' ? $f : $f->{$state}())->create(['irban_id' => $i->id, 'pkpt_item_id' => $it?->id, 'jenis_penugasan_id' => $this->j->id,
            'tanggal_mulai' => $m, 'tanggal_selesai' => $s]);
    }

    private function untuk(int $tw, ?int $irban = null): array
    {
        return app(RealisasiItemPkpt::class)->untuk(2026, $tw, $irban);
    }

    public function test_item_without_spt_is_listed_in_its_planned_quarter_only(): void
    {
        $it = $this->item($this->a, '2026-02-02', '2026-02-27', 'ket');

        $row = $this->untuk(1)[0]['item'][0];
        $this->assertSame([$it->id, [], 'ket'], [$row['item_id'], $row['spt'], $row['keterangan']]);
        $this->assertFalse($row['direvisi']);
        $this->assertSame('2026-02-02', $row['mulai']->toDateString());
        $this->assertSame([], $this->untuk(2)[0]['item']);
    }

    public function test_item_planned_in_one_quarter_with_spt_in_another_shows_in_both(): void
    {
        $it = $this->item($this->a, '2026-02-02', '2026-02-27');
        $s = $this->spt('diterima', $this->a, $it, '2026-04-06', '2026-04-10');

        $tw1 = $this->untuk(1)[0]['item'][0];
        $this->assertSame([], $tw1['spt']);
        $tw2 = $this->untuk(2)[0]['item'][0];
        $this->assertSame($it->id, $tw2['item_id']);
        $this->assertSame([$s->id], array_column($tw2['spt'], 'id'));
        $this->assertSame('tidak_sesuai', $tw2['spt'][0]['kesesuaian']);
        $this->assertSame(['id', 'nomor_lengkap', 'tanggal_spt', 'irban', 'jenis', 'mulai', 'selesai', 'kesesuaian', 'status', 'penanda', 'tim', 'ppj', 'dana', 'kendala', 'url'], array_keys($tw2['spt'][0]));
        $this->assertSame([$it->id], array_column($this->untuk(0)[0]['item'], 'item_id'));
    }

    public function test_spt_order_and_exclusions(): void
    {
        $it = $this->item($this->a, '2026-04-01', '2026-04-30');
        $late = $this->spt('diterima', $this->a, $it, '2026-04-10', '2026-04-12');
        $early2 = $this->spt('diterima', $this->a, $it, '2026-04-02', '2026-04-05');
        $early1 = $this->spt('diterima', $this->a, $it, '2026-04-02', '2026-04-06');
        $this->spt('diterima', $this->a, null, '2026-04-02', '2026-04-05');
        $this->spt('draf', $this->a, $it, '2026-04-02', '2026-04-05');
        $this->spt('menungguNomor', $this->a, $it, '2026-04-02', '2026-04-05');
        $this->spt('perluRevisi', $this->a, $it, '2026-04-02', '2026-04-05');
        $this->spt('dibatalkan', $this->a, $it, '2026-04-02', '2026-04-05');
        $this->spt('ditolak', $this->a, $it, '2026-04-02', '2026-04-05');
        $this->spt('diterima', $this->a, $it, '2025-04-02', '2025-04-05'); // other year

        $this->assertSame([$early2->id, $early1->id, $late->id], array_column($this->untuk(2)[0]['item'][0]['spt'], 'id'));
    }

    public function test_moved_item_changes_range_and_kesesuaian(): void
    {
        $it = $this->item($this->a, '2026-02-02', '2026-02-27');
        JadwalItem::geser($it, '2026-04-01', '2026-04-30');
        $this->spt('diterima', $this->a, $it, '2026-04-06', '2026-04-10');

        $row = $this->untuk(2)[0]['item'][0];
        $this->assertTrue($row['direvisi']);
        $this->assertSame(['2026-04-01', '2026-04-30'], [$row['mulai']->toDateString(), $row['selesai']->toDateString()]);
        $this->assertSame('sesuai', $row['spt'][0]['kesesuaian']);
        $this->assertSame([], $this->untuk(1)[0]['item']);
    }

    public function test_irban_filter_order_and_empty_irban(): void
    {
        $x = $this->item($this->b, '2026-03-01', '2026-03-10');
        $z = $this->item($this->b, '2026-01-01', '2026-01-10');
        $y = $this->item($this->b, '2026-01-01', '2026-03-05');

        $semua = $this->untuk(0);
        $this->assertSame([$this->a->id, $this->b->id], array_column(array_column($semua, 'irban'), 'id'));
        $this->assertSame([], $semua[0]['item']);
        $this->assertSame([$z->id, $y->id, $x->id], array_column($semua[1]['item'], 'item_id'));
        $this->assertCount(1, $this->untuk(0, $this->a->id));
        $this->assertSame([$this->b->id], array_column(array_column($this->untuk(0, $this->b->id), 'irban'), 'id'));
    }

    public function test_query_count_does_not_grow_with_items(): void
    {
        $this->untuk(0); // warm the settings cache
        $hitung = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->untuk(0);

            return count(DB::getQueryLog());
        };
        $it = $this->item($this->a, '2026-04-01', '2026-04-30');
        $this->spt('diterima', $this->a, $it, '2026-04-02', '2026-04-05');
        $satu = $hitung();
        foreach ([$this->a, $this->b] as $i) {
            for ($n = 0; $n < 3; $n++) {
                $this->spt('diterima', $i, $this->item($i, '2026-05-01', '2026-05-30'), '2026-05-02', '2026-05-05');
            }
        }
        $this->assertSame($satu, $hitung());
    }
}
