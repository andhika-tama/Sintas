<?php

namespace Tests\Feature\Rekap;

use App\Enums\PeranTim;
use App\Enums\Role;
use App\Jobs\BuatEkspor;
use App\Livewire\Pimpinan\Monitoring;
use App\Models\Ekspor as EksporModel;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Komentar;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\SptKendala;
use App\Models\SptPersonel;
use App\Models\Tim;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunMonitoring;
use App\Services\KesesuaianPkpt;
use App\Services\MonitoringSpt;
use App\Services\PenandaSpt;
use App\Services\Triwulan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Halaman Monitoring SPT (Fase 8, Task 3). Today 15 Desember 2026, TW by tanggal_selesai.
 *   i1 Irban A (INSPEKTORAT-I), Jenis Satu, 1–31 Mar 2026
 *   m1 A i1 5–20 Mar Selesai                      → Sesuai, TW I
 *   m2 A i1 25 Mar–5 Apr berjalan, kendala        → Tidak Sesuai PKPT, Penyusunan Laporan + Terlambat, TW II
 *   m5 B -  Jenis Dua 10–20 Des berjalan, open Minta Penjelasan → Non-PKPT, Pelaksanaan, TW IV
 *   m9 A i1 spt.tahun 2025 Selesai               → only with tahun=2025
 */
class HalamanMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $pimpinan;

    private Irban $a;

    private Irban $b;

    private JenisPenugasan $j2;

    private PkptItem $i1;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00', 'Asia/Jakarta'));
        $this->pimpinan = User::factory()->create(['role' => Role::Pimpinan]);
        $this->a = Irban::factory()->create(['nama' => 'Irban A', 'kode_nomor' => 'INSPEKTORAT-I']);
        $this->b = Irban::factory()->create(['nama' => 'Irban B']);
        $j1 = JenisPenugasan::factory()->create(['nama' => 'Jenis Satu']);
        $this->j2 = JenisPenugasan::factory()->create(['nama' => 'Jenis Dua']);
        $this->i1 = PkptItem::factory()->create(['irban_id' => $this->a->id, 'jenis_penugasan_id' => $j1->id, 'tanggal_mulai' => '2026-03-01', 'tanggal_selesai' => '2026-03-31']);

        $spt = function (string $state, Irban $ir, ?PkptItem $item, JenisPenugasan $j, string $m, string $s, int $tahun = 2026) {
            $spt = Spt::factory()->{$state}()->create(['irban_id' => $ir->id, 'pkpt_item_id' => $item?->id,
                'jenis_penugasan_id' => $j->id, 'tanggal_mulai' => $m, 'tanggal_selesai' => $s]);
            $spt->forceFill(['tahun' => $tahun])->save();

            return $spt;
        };
        $this->m[1] = $spt('selesai', $this->a, $this->i1, $j1, '2026-03-05', '2026-03-20');
        $this->m[2] = $spt('diterima', $this->a, $this->i1, $j1, '2026-03-25', '2026-04-05');
        $this->m[5] = $spt('diterima', $this->b, null, $this->j2, '2026-12-10', '2026-12-20');
        $this->m[9] = $spt('selesai', $this->a, $this->i1, $j1, '2025-03-05', '2025-03-20', 2025);
        SptKendala::factory()->create(['spt_id' => $this->m[2]->id, 'kategori' => 'Objek tidak siap',
            'uraian' => 'Objek sedang diaudit BPK sehingga dokumen pendukung belum dapat diserahkan kepada tim pemeriksa sampai akhir bulan.']);
        Komentar::factory()->mintaPenjelasan()->create(['spt_id' => $this->m[5]->id]);
        // m2: Tim 1 with PPJ; the others have no personnel (Tanpa tim) and no PPJ.
        $ppj = Pegawai::factory()->create(['nama_bergelar' => 'Maleha, S.H']);
        SptPersonel::create(['spt_id' => $this->m[2]->id, 'pegawai_id' => $ppj->id, 'peran' => PeranTim::Anggota, 'urutan' => 1, 'sumber' => 'tambahan',
            'tim_id' => Tim::factory()->create(['irban_id' => $this->a->id, 'nama' => 'Tim 1'])->id]);
        $this->m[2]->forceFill(['ppj_pegawai_id' => $ppj->id])->save();
    }

    private function halaman(array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($this->pimpinan)->test(Monitoring::class);
    }

    /** Nomor of the fixture SPT that the page shows, by key. */
    private function tampil(array $query): array
    {
        $html = $this->halaman($query)->html();

        return array_values(array_filter(array_keys($this->m), fn ($k) => str_contains($html, e($this->m[$k]->nomor_lengkap).'<')));
    }

    /** Keys of the fixture SPT in the order their numbers first appear in the page. */
    private function urutan(array $query): array
    {
        $html = $this->halaman($query)->html();
        $pos = [];
        foreach ($this->m as $k => $spt) {
            if (($p = strpos($html, e($spt->nomor_lengkap).'<')) !== false) {
                $pos[$k] = $p;
            }
        }
        asort($pos);

        return array_keys($pos);
    }

    public function test_default_view_is_per_pkpt(): void
    {
        $this->halaman()->assertSet('tampilan', 'pkpt')->assertSet('urut', 'tenggat')
            ->assertSeeHtml('<th scope="rowgroup"')->assertSee('Buka semua')->assertSee('Per PKPT')->assertSee('Per SPT')
            ->assertDontSee('Urutkan');
    }

    public function test_per_spt_view_lists_rows_without_group_rows(): void
    {
        Spt::query()->update(['tanggal_spt' => null]); // no Terbit line without a date
        $this->halaman(['tampilan' => 'spt'])
            ->assertDontSeeHtml('<th scope="rowgroup"')->assertDontSee('Buka semua')->assertSee('Urutkan')
            ->assertSeeHtmlInOrder(['Nomor SPT', 'Realisasi Periode', 'Kesesuaian dengan Jadwal PKPT', 'Status', 'Realisasi Dana', 'Permasalahan/Kendala'])
            ->assertDontSee('Tanggal SPT')->assertDontSee('Terbit 0')->assertDontSee('Terbit 1')->assertDontSee('Terbit 2')
            ->assertSee('Jenis Satu · Irban A', false);
        // Unfinished first by earliest end (m2 5 Apr, m5 20 Des), then the finished one.
        $this->assertSame([2, 5, 1], $this->urutan(['tampilan' => 'spt']));
    }

    public function test_urut_terbit_changes_the_order(): void
    {
        $this->m[1]->forceFill(['tanggal_spt' => '2026-03-01'])->save();
        $this->m[2]->forceFill(['tanggal_spt' => '2026-03-20'])->save();
        $this->m[5]->forceFill(['tanggal_spt' => '2026-12-01'])->save();

        $this->assertSame([5, 2, 1], $this->urutan(['tampilan' => 'spt', 'urut' => 'terbit']));
        $this->halaman(['tampilan' => 'spt', 'urut' => 'terbit'])->assertSee('Terbit 01 Desember 2026');
    }

    public function test_unknown_tampilan_and_urut_fall_back(): void
    {
        $this->halaman(['tampilan' => 'x', 'urut' => 'y'])->assertOk()->assertSet('tampilan', 'pkpt')->assertSet('urut', 'tenggat')->assertSeeHtml('<th scope="rowgroup"');
    }

    public function test_switching_view_resets_the_page(): void
    {
        $this->halaman(['tampilan' => 'pkpt', 'page' => '2'])->assertSet('paginators.page', 2)
            ->set('tampilan', 'spt')->assertSet('paginators.page', 1);
    }

    public function test_per_spt_paginates_20_rows(): void
    {
        foreach (range(1, 21) as $n) {
            Spt::factory()->diterima()->create(['irban_id' => $this->a->id, 'tanggal_mulai' => '2024-02-01', 'tanggal_selesai' => '2024-02-02'])->forceFill(['tahun' => 2024])->save();
        }

        $this->assertSame(20, substr_count($this->halaman(['tahun' => '2024', 'tampilan' => 'spt'])->html(), '<tr wire:key="r-'));
        $this->assertStringContainsString('Menampilkan 21–21 dari 21', $this->halaman(['tahun' => '2024', 'tampilan' => 'spt', 'page' => '2'])->html());
    }

    public function test_cari_filters_both_views_and_atur_ulang_clears_it_but_keeps_view(): void
    {
        $nomor = $this->m[5]->nomor_lengkap;
        foreach (['pkpt', 'spt'] as $v) {
            $this->assertSame([5], $this->tampil(['tampilan' => $v, 'cari' => $nomor]));
        }
        $this->halaman(['tampilan' => 'spt', 'urut' => 'terbit', 'cari' => $nomor])->assertSee('Atur ulang')
            ->call('aturUlang')->assertSet('cari', '')->assertSet('tampilan', 'spt')->assertSet('urut', 'terbit');
        $this->halaman(['tampilan' => 'spt', 'urut' => 'terbit'])->assertDontSee('Atur ulang');
    }

    public function test_cari_zero_is_kept_and_counted_as_active(): void
    {
        $this->halaman(['cari' => '0'])->assertSet('cari', '0')->assertViewHas('filterAktif', 1);
    }

    public function test_penanda_select_filters_terlambat(): void
    {
        $this->halaman()->assertSeeHtml('wire:model.live="penanda"')->assertSee('Terlambat (semua tahun)')
            ->set('penanda', 'terlambat')->assertSet('penanda', 'terlambat');
        $this->assertSame([2], $this->tampil(['penanda' => 'terlambat', 'tampilan' => 'spt']));
    }

    public function test_per_spt_empty_state(): void
    {
        $this->halaman(['tahun' => '2030', 'tampilan' => 'spt'])->assertSee('Tidak ada SPT sesuai filter.')->assertSee('Per SPT');
    }

    public function test_pimpinan_only_and_sidebar_item(): void
    {
        $this->actingAs($this->pimpinan)->get('/pimpinan/monitoring')->assertOk()
            ->assertSee('<h1', false)->assertSee('Monitoring SPT')->assertSee('Realisasi SPT per item PKPT atau per SPT.')
            ->assertSee(route('pimpinan.monitoring'), false);
        foreach ([Role::Irban, Role::BagianUmum, Role::Admin] as $role) {
            $u = User::factory()->create(['role' => $role, 'irban_id' => $role === Role::Irban ? $this->a->id : null]);
            $this->actingAs($u)->get('/pimpinan/monitoring')->assertForbidden();
            Livewire::actingAs($u)->test(Monitoring::class)->assertForbidden();
        }
    }

    public function test_groups_columns_and_rowgroup_headers(): void
    {
        $this->halaman()
            ->assertSeeHtmlInOrder(['Jenis Penugasan', 'Periode Penugasan (PKPT)', 'Realisasi Periode', 'Kesesuaian dengan Jadwal PKPT', 'Status', 'Realisasi Dana', 'Permasalahan/Kendala'])
            ->assertSeeHtmlInOrder(['<th scope="rowgroup"', 'Jenis Satu', '1–31 Maret 2026', '2 SPT', '<th scope="rowgroup"', 'Di luar PKPT', '1 SPT'])
            ->assertSeeHtml('aria-label="Tampilkan SPT Jenis Satu 1–31 Maret 2026"')
            ->assertSeeHtml('aria-label="Tampilkan SPT Di luar PKPT"')
            ->assertSee(['Buka semua', 'Tutup semua']);

        $this->assertSame(2, substr_count($this->halaman()->html(), '<th scope="rowgroup"'));
    }

    public function test_groups_collapsed_by_default_and_open_when_a_filter_other_than_tahun_is_active(): void
    {
        $this->assertStringNotContainsString('buka: true', $this->halaman(['tahun' => 2026])->html());
        $this->assertStringNotContainsString('buka: false', $this->halaman(['irban' => $this->a->id])->html());
    }

    public function test_cells(): void
    {
        $this->halaman()
            ->assertSeeHtml('href="'.route('spt.show', $this->m[2]).'"')
            ->assertSeeHtmlInOrder([e($this->m[1]->nomor_lengkap), '5–20 Maret 2026', 'Sesuai', 'Selesai'])
            ->assertSeeHtmlInOrder([e($this->m[2]->nomor_lengkap), '25 Maret – 5 April 2026', 'Tidak Sesuai PKPT', 'Penyusunan Laporan', 'Terlambat', 'Objek tidak siap', 'Objek sedang diaudit BPK', 'Selengkapnya'])
            ->assertSeeHtmlInOrder([e($this->m[5]->nomor_lengkap), '10–20 Desember 2026', 'Non-PKPT', 'Pelaksanaan']);
    }

    /** 1.3 Task 4: Realisasi Dana in both views and the mobile cards: Rp600.000, Rp0, and "–" when not filled. */
    public function test_realisasi_dana_in_both_views_and_cards(): void
    {
        $this->m[1]->forceFill(['realisasi_dana' => 600000])->save();
        $this->m[5]->forceFill(['realisasi_dana' => 0])->save();

        foreach ([[], ['tampilan' => 'spt']] as $q) {
            $html = $this->halaman($q)->assertSee('Realisasi Dana')->html();
            $this->assertSame(3, substr_count($html, 'Realisasi Dana: ')); // one mobile line per SPT row (m1, m2, m5)
            $this->assertStringContainsString('Rp600.000', $html);
            $this->assertStringContainsString('Realisasi Dana: Rp600.000', $html);
            $this->assertStringContainsString('Realisasi Dana: Rp0', $html);
            $this->assertStringContainsString('Realisasi Dana: –', $html);
        }
        $this->halaman()->assertSeeHtmlInOrder([e($this->m[1]->nomor_lengkap), 'Selesai', 'Rp600.000']);
        $this->halaman()->assertSeeHtml('colspan="7"');
    }

    public function test_each_filter_from_the_url(): void
    {
        $this->assertSame([1, 2, 5], $this->tampil([]));
        $this->assertSame([9], $this->tampil(['tahun' => '2025']));
        $this->assertSame([2], $this->tampil(['tw' => '2']));
        $this->assertSame([5], $this->tampil(['irban' => (string) $this->b->id]));
        $this->assertSame([1], $this->tampil(['status' => 'selesai']));
        $this->assertSame([2, 5], $this->tampil(['status' => 'berjalan']));
        $this->assertSame([5], $this->tampil(['status' => 'pelaksanaan']));
        $this->assertSame([5], $this->tampil(['jenis' => (string) $this->j2->id]));
        $this->assertSame([2], $this->tampil(['kesesuaian' => 'tidak_sesuai']));
        $this->assertSame([2], $this->tampil(['penanda' => 'terlambat']));
        $this->assertSame([5], $this->tampil(['penjelasan' => 'terbuka']));
    }

    /** 1.3 Task 1: the three selects come from the URL, Lainnya is an option, fictitious ids fall back, Atur ulang clears them. */
    public function test_klasifikasi_kode_arsip_and_jenis_from_the_url(): void
    {
        $reguler = Klasifikasi::where('kode', 'REGULER')->value('id');
        $k800 = KodeArsip::firstOrCreate(['kode' => '800'], ['nama' => 'Kepegawaian'])->id;
        Spt::whereKey($this->m[1]->id)->update(['klasifikasi_id' => $reguler]);
        Spt::whereKey($this->m[2]->id)->update(['kode_arsip_id' => $k800]);
        Spt::whereKey($this->m[5]->id)->update(['jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => 'Evaluasi khusus']);

        $this->assertSame([1], $this->tampil(['klasifikasi' => (string) $reguler]));
        $this->assertSame([2], $this->tampil(['kode_arsip' => (string) $k800]));
        $this->assertSame([5], $this->tampil(['jenis' => 'lainnya']));
        $this->assertSame([5], $this->tampil(['jenis' => 'lainnya', 'klasifikasi' => '999999'])); // fictitious id ignored, no 500
        $this->halaman(['klasifikasi' => '999999', 'kode_arsip' => 'x'])->assertSet('klasifikasi', '')->assertSet('kode_arsip', '')
            ->assertSee('Semua klasifikasi')->assertSee('Semua kode arsip')->assertSee('700 · Pengawasan Umum')->assertSee('Lainnya');
        $this->halaman(['klasifikasi' => (string) $reguler, 'kode_arsip' => (string) $k800, 'jenis' => 'lainnya'])->call('aturUlang')
            ->assertSet('klasifikasi', '')->assertSet('kode_arsip', '')->assertSet('jenis', '');
    }

    public function test_invalid_url_values_fall_back(): void
    {
        $this->halaman(['tahun' => 'abc', 'tw' => '9', 'irban' => '999', 'status' => 'xyz', 'jenis' => '999', 'kesesuaian' => 'x', 'penanda' => 'x', 'penjelasan' => 'x'])
            ->assertSet('tahun', '2026')->assertSet('tw', '')->assertSet('irban', '')->assertSet('status', '')
            ->assertSet('jenis', '')->assertSet('kesesuaian', '')->assertSet('penanda', '')->assertSet('penjelasan', '');
        $this->assertSame([1, 2, 5], $this->tampil(['irban' => '999', 'status' => 'xyz']));
    }

    public function test_stat_card_filters_show_chips_and_reset(): void
    {
        $this->halaman(['penanda' => 'terlambat', 'penjelasan' => 'terbuka'])
            ->assertSee(['Terlambat', 'Permintaan Penjelasan Terbuka', 'Atur ulang'])
            ->assertSeeHtml('aria-label="Hapus filter Terlambat (semua tahun)"')
            ->call('aturUlang')
            ->assertSet('penanda', '')->assertSet('penjelasan', '')->assertSet('tahun', '2026');
    }

    public function test_empty_state(): void
    {
        $this->halaman(['tahun' => '2030'])->assertSee('Tidak ada SPT sesuai filter.');
    }

    public function test_twenty_groups_per_page(): void
    {
        $pkpt = Pkpt::factory()->create();
        foreach (range(1, 21) as $n) {
            $item = PkptItem::factory()->create(['pkpt_id' => $pkpt->id, 'irban_id' => $this->a->id, 'tanggal_mulai' => '2024-01-01', 'tanggal_selesai' => '2024-12-31']);
            Spt::factory()->diterima()->create(['irban_id' => $this->a->id, 'pkpt_item_id' => $item->id, 'tanggal_mulai' => '2024-02-01', 'tanggal_selesai' => '2024-02-02'])
                ->forceFill(['tahun' => 2024])->save();
        }

        $this->assertSame(20, substr_count($this->halaman(['tahun' => '2024'])->html(), '<th scope="rowgroup"'));
        $page2 = $this->halaman(['tahun' => '2024', 'page' => '2'])->html();
        $this->assertSame(1, substr_count($page2, '<th scope="rowgroup"'));
        $this->assertStringContainsString('Menampilkan 21–21 dari 21', $page2);
    }

    public function test_export_xlsx_follows_filters(): void
    {
        $this->halaman(['irban' => (string) $this->a->id])->call('ekspor', 'xlsx')->assertFileDownloaded('monitoring-spt-2026-irban-INSPEKTORAT-I.xlsx');

        $r = app(Ekspor::class)->minta($this->pimpinan, 'monitoring', 'xlsx', ['irban' => (string) $this->a->id]);
        $sheet = array_slice(IOFactory::load($r->getFile()->getPathname())->getActiveSheet()->toArray(), 3); // rows 1-3: title, filter, Dibuat

        $this->assertSame(['Nomor SPT', 'Irban', 'Tim · PPJ', 'Jenis Penugasan', 'Periode Penugasan (PKPT)', 'Realisasi Periode', 'Kesesuaian dengan Jadwal PKPT', 'Status', 'Realisasi Dana', 'Permasalahan/Kendala'], $sheet[0]);
        $this->assertCount(3, $sheet); // m1, m2 (Irban B's m5 and 2025's m9 filtered out)
        $this->assertSame([$this->m[1]->nomor_lengkap, 'Irban A', 'Tanpa tim · –', 'Jenis Satu', '1–31 Maret 2026', '5–20 Maret 2026', 'Sesuai', 'Selesai', null, null], $sheet[1]);
        $this->assertSame([$this->m[2]->nomor_lengkap, 'Irban A', 'Tim 1 · Maleha, S.H', 'Jenis Satu', '1–31 Maret 2026', '25 Maret – 5 April 2026', 'Tidak Sesuai PKPT', 'Penyusunan Laporan, Terlambat', null,
            'Objek tidak siap: Objek sedang diaudit BPK sehingga dokumen pendukung belum dapat diserahkan kepada tim pemeriksa sampai akhir bulan.'], $sheet[2]);

        $semua = app(Ekspor::class)->minta($this->pimpinan, 'monitoring', 'xlsx', []);
        $this->assertStringContainsString('monitoring-spt-2026.xlsx', $semua->headers->get('content-disposition'));
        $rows = array_slice(IOFactory::load($semua->getFile()->getPathname())->getActiveSheet()->toArray(), 3); // rows 1-3: title, filter, Dibuat
        $this->assertSame([$this->m[5]->nomor_lengkap, 'Irban B', 'Tanpa tim · –', 'Di luar PKPT', '–', '10–20 Desember 2026', 'Non-PKPT', 'Pelaksanaan', null, null], $rows[3]);
    }

    public function test_tim_ppj_shows_in_both_views_and_in_the_pdf(): void
    {
        foreach (['pkpt', 'spt'] as $tampilan) {
            $this->halaman(['tampilan' => $tampilan, 'tahun' => '2026'])->assertSee('Tim 1 · Maleha, S.H (PPJ)')->assertSee('Tanpa tim · –');
        }

        $html = app(PembangunMonitoring::class)->html($this->pimpinan, app(MonitoringSpt::class)->filter([]));
        $this->assertStringContainsString('Tim 1 · Maleha, S.H (PPJ)', $html);
        $this->assertStringNotContainsString('<th>Tim · PPJ</th>', $html);
        $this->assertStringContainsString('<th>Irban</th>', $html);
    }

    public function test_direct_export_reads_the_rows_once(): void
    {
        $hitung = new \ArrayObject;
        $this->app->bind(MonitoringSpt::class, fn ($app) => new class($app->make(Triwulan::class), $app->make(PenandaSpt::class), $app->make(KesesuaianPkpt::class), $hitung) extends MonitoringSpt
        {
            public function __construct($t, $p, $k, private \ArrayObject $hitung)
            {
                parent::__construct($t, $p, $k);
            }

            public function baris(array $filter): array
            {
                $this->hitung->append($filter);

                return parent::baris($filter);
            }
        });

        foreach (['xlsx', 'pdf'] as $format) {
            $hitung->exchangeArray([]);
            $r = app(Ekspor::class)->minta($this->pimpinan, 'monitoring', $format, ['irban' => (string) $this->a->id]);
            if ($format === 'pdf') {
                TestResponse::fromBaseResponse($r)->streamedContent();
            } else {
                $this->assertCount(3, array_slice(IOFactory::load($r->getFile()->getPathname())->getActiveSheet()->toArray(), 3));
            }
            $this->assertCount(1, $hitung, $format);
        }

        // A different filter on the same builder is not served from the memo.
        $p = app(PembangunMonitoring::class);
        $hitung->exchangeArray([]);
        $p->jumlah($this->pimpinan, $p->filter($this->pimpinan, []));
        $this->assertSame(1, $p->jumlah($this->pimpinan, $p->filter($this->pimpinan, ['jenis' => (string) $this->j2->id])));
        $this->assertCount(2, $hitung);
    }

    public function test_export_follows_cari_and_lists_it_in_the_pdf_summary(): void
    {
        $this->m[2]->forceFill(['nomor_lengkap' => '700/002/X'])->save();
        $p = app(PembangunMonitoring::class);
        $sheet = array_slice(IOFactory::load(app(Ekspor::class)->minta($this->pimpinan, 'monitoring', 'xlsx', ['cari' => ' 700 '])->getFile()->getPathname())->getActiveSheet()->toArray(), 3); // rows 1-3: title, filter, Dibuat

        $this->assertCount(2, $sheet); // header + m2 only
        $this->assertSame('700/002/X', $sheet[1][0]);
        $this->assertStringContainsString('Cari: 700', $p->html($this->pimpinan, $p->filter($this->pimpinan, ['cari' => '700'])));
        $this->assertStringNotContainsString('Cari:', $p->html($this->pimpinan, $p->filter($this->pimpinan, [])));
    }

    public function test_export_pdf(): void
    {
        $r = TestResponse::fromBaseResponse(app(Ekspor::class)->minta($this->pimpinan, 'monitoring', 'pdf', ['tahun' => '2026']));

        $r->assertDownload('monitoring-spt-2026.pdf');
        $this->assertStringStartsWith('%PDF', $r->streamedContent());
        $p = app(PembangunMonitoring::class);
        $this->assertStringContainsString(e($this->m[2]->nomor_lengkap), $p->html($this->pimpinan, $p->filter($this->pimpinan, [])));
    }

    public function test_builder_validates_filter_and_authorizes(): void
    {
        $p = app(PembangunMonitoring::class);
        $f = $p->filter($this->pimpinan, ['tahun' => 'abc', 'irban' => '999', 'status' => 'xyz', 'jenis' => (string) $this->j2->id]);

        $this->assertSame(['tahun' => 2026, 'tw' => null, 'irban' => null, 'status' => null, 'klasifikasi' => null, 'kode_arsip' => null, 'jenis' => $this->j2->id, 'kesesuaian' => null, 'penanda' => null, 'penjelasan' => null, 'cari' => null], $f);
        $this->assertSame(1, $p->jumlah($this->pimpinan, $f));
        $this->assertSame('/pimpinan/monitoring', $p->halaman($this->pimpinan));

        $this->expectException(AuthorizationException::class);
        $p->otorisasi(User::factory()->create(['role' => Role::BagianUmum]));
    }

    public function test_more_than_limit_is_queued_with_toast(): void
    {
        Queue::fake();
        $this->app->bind(PembangunMonitoring::class, fn () => new class(app(MonitoringSpt::class)) extends PembangunMonitoring
        {
            public function jumlah(User $u, array $f): int
            {
                return Ekspor::BATAS_LANGSUNG + 1;
            }
        });

        $this->halaman(['irban' => (string) $this->a->id, 'status' => 'xyz'])->call('ekspor', 'pdf')
            ->assertDispatched('toast', type: 'info', message: 'Ekspor sedang disiapkan. Anda akan menerima notifikasi saat file siap.');

        $e = EksporModel::firstOrFail();
        $this->assertSame(['monitoring', 'pdf', 'monitoring-spt-2026-irban-INSPEKTORAT-I.pdf', $this->pimpinan->id], [$e->jenis, $e->format, $e->nama_file, $e->user_id]);
        $this->assertSame($this->a->id, $e->filter['irban']);
        $this->assertNull($e->filter['status']);
        Queue::assertPushed(BuatEkspor::class);
    }
}
