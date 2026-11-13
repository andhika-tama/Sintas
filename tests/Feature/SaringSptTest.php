<?php

namespace Tests\Feature;

use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Services\SaringSpt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SaringSpt (1.3 Task 1): validated Klasifikasi / Kode Arsip / Jenis filter shared by Monitoring, Register and the exports. */
class SaringSptTest extends TestCase
{
    use RefreshDatabase;

    private Klasifikasi $k1;

    private Klasifikasi $k2;

    private KodeArsip $a7;

    private KodeArsip $a8;

    private JenisPenugasan $j1;

    private JenisPenugasan $j2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->k1 = Klasifikasi::where('kode', 'REGULER')->firstOrFail();
        $this->k2 = Klasifikasi::where('kode', 'KHUSUS')->firstOrFail();
        $this->a7 = KodeArsip::firstOrCreate(['kode' => '700'], ['nama' => 'Pengawasan Umum']);
        $this->a8 = KodeArsip::firstOrCreate(['kode' => '800'], ['nama' => 'Kepegawaian']);
        $this->j1 = JenisPenugasan::factory()->create(['nama' => 'Zeta']);
        $this->j2 = JenisPenugasan::factory()->create(['nama' => 'Alfa']);
    }

    public function test_dari_validates_ids_and_lainnya(): void
    {
        $s = app(SaringSpt::class);
        $kosong = ['klasifikasi' => null, 'kode_arsip' => null, 'jenis' => null];
        $this->assertSame($kosong, $s->dari([]));
        $this->assertSame($kosong, $s->dari(['klasifikasi' => '999999', 'kode_arsip' => 999999, 'jenis' => 'sampah']));
        $this->assertSame($kosong, $s->dari(['klasifikasi' => '0', 'kode_arsip' => ['1'], 'jenis' => '0']));
        $this->assertSame(['klasifikasi' => $this->k1->id, 'kode_arsip' => $this->a7->id, 'jenis' => $this->j1->id],
            $s->dari(['klasifikasi' => (string) $this->k1->id, 'kode_arsip' => $this->a7->id, 'jenis' => (string) $this->j1->id]));
        $this->assertSame('lainnya', $s->dari(['jenis' => 'lainnya'])['jenis']);
        $this->assertSame($kosong, $s->dari(['jenis' => 'LAINNYA ']));
    }

    public function test_opsi_order_and_labels(): void
    {
        $this->k2->forceFill(['aktif' => false])->save();
        $o = app(SaringSpt::class)->opsi();

        $this->assertSame(Klasifikasi::urut()->get()->mapWithKeys(fn ($k) => [$k->id => $k->label()])->all(), $o['klasifikasi']);
        $this->assertSame($this->k2->nama.' (nonaktif)', $o['klasifikasi'][$this->k2->id]);
        $this->assertSame('700 · Pengawasan Umum', $o['kode_arsip'][$this->a7->id]);
        $kode = array_keys($o['kode_arsip']);
        $this->assertLessThan(array_search($this->a8->id, $kode), array_search($this->a7->id, $kode));
        $this->assertSame('Alfa', $o['jenis'][$this->j2->id]);
        $jenis = array_values($o['jenis']);
        $this->assertSame('Lainnya', end($jenis));
        $this->assertSame('Lainnya', $o['jenis']['lainnya']);
        $this->assertLessThan(array_search('Zeta', $jenis), array_search('Alfa', $jenis));
    }

    public function test_scope_filters_each_key_including_lainnya(): void
    {
        $a = Spt::factory()->diterima()->create(['klasifikasi_id' => $this->k1->id, 'kode_arsip_id' => $this->a7->id, 'jenis_penugasan_id' => $this->j1->id]);
        $b = Spt::factory()->diterima()->create(['klasifikasi_id' => $this->k2->id, 'kode_arsip_id' => $this->a8->id, 'jenis_penugasan_id' => $this->j2->id]);
        $c = Spt::factory()->diterima()->create(['klasifikasi_id' => $this->k2->id, 'kode_arsip_id' => $this->a7->id, 'jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => 'Evaluasi khusus']);
        $d = Spt::factory()->diterima()->create(['klasifikasi_id' => $this->k2->id, 'kode_arsip_id' => $this->a7->id, 'jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => null]);
        $ids = fn (array $f) => Spt::saring($f)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], $ids([]));
        $this->assertSame([$a->id], $ids(['klasifikasi' => $this->k1->id]));
        $this->assertSame([$b->id, $c->id, $d->id], $ids(['klasifikasi' => $this->k2->id]));
        $this->assertSame([$a->id, $c->id, $d->id], $ids(['kode_arsip' => $this->a7->id]));
        $this->assertSame([$b->id], $ids(['jenis' => $this->j2->id]));
        $this->assertSame([$c->id], $ids(['jenis' => 'lainnya']));
        $this->assertSame([$c->id], $ids(['klasifikasi' => $this->k2->id, 'kode_arsip' => $this->a7->id, 'jenis' => 'lainnya']));
    }

    public function test_ringkasan_and_aktif(): void
    {
        $s = app(SaringSpt::class);
        $this->assertSame([], $s->ringkasan(['klasifikasi' => null, 'kode_arsip' => null, 'jenis' => null]));
        $this->assertFalse($s->aktif([]));
        $this->assertTrue($s->aktif(['jenis' => 'lainnya']));
        $this->assertSame(['Klasifikasi: '.$this->k1->nama, 'Kode Arsip: 700 · Pengawasan Umum', 'Jenis: Zeta'],
            $s->ringkasan(['klasifikasi' => $this->k1->id, 'kode_arsip' => $this->a7->id, 'jenis' => $this->j1->id]));
        $this->assertSame(['Jenis: Lainnya'], $s->ringkasan(['jenis' => 'lainnya']));
    }
}
