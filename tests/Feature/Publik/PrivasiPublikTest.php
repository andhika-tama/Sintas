<?php

namespace Tests\Feature\Publik;

use App\Livewire\Publik\Jadwal;
use App\Livewire\Publik\Realisasi;
use App\Models\Komentar;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\PkptPerubahan;
use App\Models\SptDokumen;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 9 completion criterion (PRD §9.6, §14 butir 7): no never-public field reaches any public response.
 * Every forbidden field of the visible SPT v1 (and v2) carries its own unique marker; the fixture's excluded SPT
 * (KHUSUS, berjalan, tanpa item, dibatalkan, PKPT draf, belum ditandai) must not show either (Jenis/irban/dates are allowed
 * data of the visible ones, so exclusion is proven by their counts in PublikFixture-based tests; here by their markers).
 */
class PrivasiPublikTest extends TestCase
{
    use PublikFixture {
        setUp as protected siapkanFixture;
    }
    use RefreshDatabase;

    private const NOMOR = 'MK-NOMOR-7741/INSP/2026';

    private const DESA = 'MK-DESA-Sukamaju';

    /** Private marker => planted field. */
    private const RAHASIA = [
        'MK-PEGAWAI-Budi', '199001012020011001', 'MK-PERIODE-2025', 'MK-URAIAN-pemeriksaan', 'MK-CATATAN-bu', 'MK-LHP-900',
        'MK-KETERANGAN-item', 'MK-KENDALA-hujan', 'MK-KOMENTAR-tanya', 'MK-DOKUMEN-final.pdf', 'MK-USER-Penginput',
        // Fase 9.2 Task 9 / 1.2: Perubahan PKPT and Perubahan Penugasan never reach the public pages (only "Tambahan · Perubahan ke-N" and "semula").
        'MK-ALASAN-perubahan', 'MK-SK-Perubahan-77', 'MK-ALASAN-penugasan', 'MK-CATATAN-tolak', 'MK-KOMENTAR-putusan', 'perubahan-sk/mk-berkas.pdf',
        // Versi 1.1.4: Nomor SPPD stays internal.
        'MK-SPPD-0042',
        // 1.2: free-text Lainnya jenis/objek of non-PKPT SPT.
        'MK-JENIS-lainnya', 'MK-OBJEK-lainnya',
    ];

    protected function setUp(): void
    {
        $this->siapkanFixture();

        $this->desaX1->forceFill(['nama' => self::DESA])->save();
        DB::table('pkpt_item')->update(['keterangan' => 'MK-KETERANGAN-item']);
        DB::table('spt')->update([
            'periode_diperiksa' => 'MK-PERIODE-2025', 'uraian_untuk' => 'MK-URAIAN-pemeriksaan', 'catatan_bagian_umum' => 'MK-CATATAN-bu',
            'nomor_lhp' => 'MK-LHP-900', 'nomor_sppd' => 'MK-SPPD-0042',
            'jenis_penugasan_lainnya' => 'MK-JENIS-lainnya', 'objek_lainnya' => 'MK-OBJEK-lainnya',
        ]);
        DB::table('spt')->where('id', $this->v1->id)->update(['nomor_lengkap' => self::NOMOR]);
        Pegawai::query()->update(['nama_bergelar' => 'MK-PEGAWAI-Budi']);
        DB::table('spt_kendala')->update(['uraian' => 'MK-KENDALA-hujan']);

        $user = User::factory()->pimpinan()->create(['name' => 'MK-USER-Penginput']);
        Komentar::factory()->create(['spt_id' => $this->v1->id, 'user_id' => $user->id, 'isi' => 'MK-KOMENTAR-tanya']);
        SptDokumen::create(['spt_id' => $this->v1->id, 'jenis' => 'final', 'path' => 'spt/1/x.pdf', 'nama_asli' => 'MK-DOKUMEN-final.pdf',
            'ukuran' => 10, 'sha256' => str_repeat('a', 64), 'diunggah_oleh' => $user->id]);

        // An approved package that adds b1 and moved a1 (snapshot differs), with an SK; plus a pending and a rejected Perubahan Penugasan.
        $paket = PkptPerubahan::factory()->disetujui(1)->create(['pkpt_id' => $this->a1->pkpt_id, 'alasan' => 'MK-ALASAN-perubahan',
            'komentar_putusan' => 'MK-KOMENTAR-putusan', 'nomor_sk' => 'MK-SK-Perubahan-77', 'tanggal_sk' => '2026-03-01',
            'berkas_sk' => 'perubahan-sk/mk-berkas.pdf', 'hash_sk' => str_repeat('b', 64)]);
        $paket->operasi()->create(['aksi' => 'tambah', 'pkpt_item_id' => $this->b1->id, 'urutan' => 1, 'data_baru' => ['irban_id' => $this->b->id]]);
        DB::table('pkpt_item')->where('id', $this->b1->id)->update(['ditambahkan_perubahan_id' => $paket->id]);
        DB::table('pkpt_item')->where('id', $this->a1->id)->update(['tanggal_mulai_disahkan' => '2026-01-12', 'tanggal_selesai_disahkan' => '2026-03-20']);
        PerubahanPenugasan::factory()->create(['pkpt_item_id' => $this->a1->id, 'alasan' => 'MK-ALASAN-penugasan', 'diajukan_oleh' => $user->id]);
        PerubahanPenugasan::factory()->create(['pkpt_item_id' => $this->a1->id, 'alasan' => 'MK-ALASAN-penugasan', 'diajukan_oleh' => $user->id,
            'status' => 'ditolak', 'catatan_putusan' => 'MK-CATATAN-tolak']);
    }

    public function test_jadwal_shows_tambahan_label_and_semula_only(): void
    {
        foreach (['/jadwal', '/jadwal?tampilan=bulanan'] as $url) {
            $this->get($url)->assertOk()->assertSee('Tambahan · Perubahan ke-1')->assertSee('Jadwal direvisi: semula 12 Januari – 20 Maret 2026');
        }
    }

    /** @return array<string, string> label => response text, including Livewire updates and their snapshots. */
    private function respons(): array
    {
        $out = [];
        foreach (['/', '/jadwal', '/jadwal?tampilan=bulanan', '/realisasi', '/realisasi?tahun=2026&tw=1&bulan=2&jenis='.$this->j1->id.'&urut=asc'] as $url) {
            $out[$url] = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($url === '/' ? 'dari 3 SPT yang direncanakan' : ($url[1] === 'j' ? 'Jenis Satu, Irban A, Kec X' : 'Jenis Satu'), $out[$url], "halaman kosong: $url");
        }

        $json = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE; // default escaping turns '/' into '\/' and would hide the nomor marker
        $semua = fn ($c) => $c->html().json_encode($c->snapshot, $json).json_encode($c->effects, $json);
        $r = Livewire::test(Realisasi::class);
        foreach ([
            ['tahun', '2026'], ['tw', '1'], ['bulan', '2'], ['irban', (string) $this->a->id], ['wilayah', (string) $this->kecX->id],
            ['jenis', (string) $this->j1->id], ['urut', 'asc'], ['perIrban', true], ['proporsi', 'jenis'], ['twGrafik', '2'],
        ] as [$prop, $nilai]) {
            $out["realisasi set $prop"] = $semua($r->set($prop, $nilai));
            $r->assertSee('Realisasi Pengawasan');
        }
        $out['realisasi page 2'] = $semua($r->call('setPage', 2));

        $j = Livewire::test(Jadwal::class);
        foreach ([['tahun', '2026'], ['irban', (string) $this->a->id], ['wilayahTab', '1']] as [$prop, $nilai]) {
            $out["jadwal set $prop"] = $semua($j->set($prop, $nilai));
            $j->assertSee('Jadwal PKPT');
        }

        return $out;
    }

    public function test_no_private_field_appears_on_any_public_response(): void
    {
        foreach ($this->respons() as $label => $isi) {
            foreach ([...self::RAHASIA, self::NOMOR, self::DESA] as $marker) {
                $this->assertStringNotContainsString($marker, $isi, "$marker bocor di $label");
            }
        }
    }

    public function test_allowed_fields_of_the_visible_spt_appear(): void
    {
        $html = $this->get('/realisasi?tahun=2026')->assertOk()->getContent();
        foreach (['Jenis Satu', 'Irban A', 'Kec X', '3–20 Februari 2026', 'Jenis Dua', 'Irban B', 'Dinas D, Kec Y', '28 Mei – 2 Juni 2026'] as $teks) {
            $this->assertStringContainsString($teks, $html);
        }
        // 2 visible SPT only: the six excluded ones (KHUSUS, berjalan, tanpa item, dibatalkan, PKPT draf, belum ditandai) are not counted.
        $this->assertStringContainsString('2 SPT selesai dari 3 SPT yang direncanakan', preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function test_nomor_and_object_names_appear_on_realisasi_only_when_the_settings_are_on(): void
    {
        app(Settings::class)->set('publik.tampilkan_nomor_spt', true);
        app(Settings::class)->set('publik.tampilkan_nama_objek', true);

        $html = $this->get('/realisasi?tahun=2026')->assertOk()->getContent();
        $this->assertStringContainsString(self::NOMOR, $html);
        $this->assertStringContainsString(self::DESA, $html);

        foreach (['/', '/jadwal', '/jadwal?tampilan=bulanan'] as $url) {
            $isi = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::NOMOR, $isi, $url);
            $this->assertStringNotContainsString(self::DESA, $isi, $url);
        }

        // Everything else stays private even with both settings on.
        foreach ($this->respons() as $label => $isi) {
            foreach (self::RAHASIA as $marker) {
                $this->assertStringNotContainsString($marker, $isi, "$marker bocor di $label");
            }
            if (! str_contains($label, 'realisasi')) {
                $this->assertStringNotContainsString(self::NOMOR, $isi, $label);
                $this->assertStringNotContainsString(self::DESA, $isi, $label);
            }
        }
    }

    public function test_no_private_field_appears_with_a_warm_cache_and_after_toggling_the_settings(): void
    {
        config(['cache.default' => 'array']);
        $this->respons(); // warms every cached aggregate
        $pengaturan = app(Settings::class);
        foreach ([true, false] as $nilai) {
            $pengaturan->set('publik.tampilkan_nomor_spt', $nilai);
            foreach ($this->respons() as $label => $isi) {
                foreach ([...self::RAHASIA, self::DESA] as $marker) {
                    $this->assertStringNotContainsString($marker, $isi, "$marker bocor di $label (cache hangat)");
                }
                if (! $nilai || ! str_contains($label, 'realisasi')) {
                    $this->assertStringNotContainsString(self::NOMOR, $isi, "nomor bocor di $label");
                }
            }
        }

        $kunci = array_filter(array_keys((fn () => $this->storage)->call(Cache::getStore())), fn ($k) => str_starts_with($k, 'publik:') && $k !== 'publik:versi');
        $this->assertNotEmpty($kunci);
        foreach ($kunci as $k) {
            $muatan = serialize(Cache::get($k));
            $this->assertStringNotContainsString('App'.chr(92).'Models'.chr(92), $muatan, $k);
            foreach ([...self::RAHASIA, self::NOMOR, self::DESA] as $marker) {
                $this->assertStringNotContainsString($marker, $muatan, "$marker tersimpan di cache $k");
            }
        }
    }
}
