<?php

namespace Tests\Feature\Rekap;

use App\Enums\Role;
use App\Livewire\Rekap;
use App\Models\Klasifikasi;
use App\Models\Pkpt;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunRekap;
use App\Services\RekapTriwulan;
use App\Services\Triwulan;
use App\Support\Navigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Halaman Rekap Triwulanan / Rekap Saya (Fase 8, Task 4) on the hand-computed RekapFixture; the expected numbers are those of RekapTriwulanTest. */
class HalamanRekapTest extends TestCase
{
    use RefreshDatabase;
    use RekapFixture {
        setUp as private fixtureSetUp;
    }

    private User $pimpinan;

    private User $irbanA;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $this->withoutVite();
        $this->pimpinan = User::factory()->create(['role' => Role::Pimpinan]);
        $this->irbanA = User::factory()->create(['role' => Role::Irban, 'irban_id' => $this->a->id]);
    }

    private function halaman(User $u, array $props = [])
    {
        $t = Livewire::actingAs($u)->test(Rekap::class);
        foreach ($props as $k => $v) {
            $t->set($k, $v);
        }

        return $t;
    }

    /** Rows of every sheet of a downloaded xlsx as "c1 c2 ..." strings (empty cells dropped). */
    private function rowsXlsx($r): array
    {
        $rows = array_merge(...array_map(fn ($s) => $s->toArray(), IOFactory::load($r->getFile()->getPathname())->getAllSheets()));

        return array_map(fn ($row) => implode(' ', array_filter($row, fn ($c) => $c !== null && $c !== '')), $rows);
    }

    /** Table rows of the page as "label c1 c2 ..." strings. */
    private function baris(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $m);

        return array_map(fn ($r) => trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</', ' </', $r)))), $m[1]);
    }

    public function test_routes_titles_and_sidebar(): void
    {
        $this->actingAs($this->pimpinan)->get('/pimpinan/rekap')->assertOk()->assertSee('<h1', false)->assertSee('Rekap Triwulanan')->assertSee(route('pimpinan.rekap'), false);
        $this->actingAs($this->irbanA)->get('/irban/rekap')->assertOk()->assertSee('Rekap Saya');
        $this->actingAs($this->irbanA)->get('/pimpinan/rekap')->assertForbidden();
        $this->actingAs($this->pimpinan)->get('/irban/rekap')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::BagianUmum]))->get('/pimpinan/rekap')->assertForbidden();
        $this->assertContains('Rekap Triwulanan', array_column(Navigation::links(Role::Pimpinan), 'label'));
        $this->assertContains('Rekap Saya', array_column(Navigation::links(Role::Irban), 'label'));
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(Rekap::class)->assertForbidden();
    }

    public function test_pimpinan_gabungan_and_per_irban_tabs_show_the_fixture_numbers(): void
    {
        $t = $this->halaman($this->pimpinan, ['tab' => 'gabungan']);
        $b = $this->baris($t->html());
        // Gabungan: rencana 2,3,1,3 = 9; realisasi 1,2,0,1 = 4; Total 44.
        foreach (['TW I 2 1 50', 'TW II 3 2 67', 'TW III 1 0 0', 'TW IV 3 1 33', 'Total 9 4 44', 'REGULER 6 2 4', 'Total 8 3 5', 'Jenis Satu 6 2 33 5', 'Jenis Dua 3 2 67 3', 'Total 9 4 44 8'] as $r) {
            $this->assertContains($r, $b, $r);
        }
        $t->assertSee('Per Irban')->assertSee('Gabungan')->assertSee('Rencana vs Realisasi')->assertSee('Per Klasifikasi')->assertSee('Per Jenis Penugasan');
        // Pimpinan's Irban filter lists the irbans, so "no per-irban table" is checked in the panel only.
        $this->assertStringNotContainsString('Irban A', substr($t->html(), strpos($t->html(), 'id="rekap-panel"')));

        $b = $this->baris($this->halaman($this->pimpinan)->html()); // default tab: Per Irban
        foreach (['TW I 2 1 50', 'TW IV 3 1 33', 'Total 6 3 50', 'Total 3 1 33', 'TW III 0 0 –'] as $r) {
            $this->assertContains($r, $b, $r);
        }
        $this->halaman($this->pimpinan)->assertSee('Irban A')->assertSee('Irban B');
    }

    public function test_irban_sees_only_own_irban_on_ringkasan_and_tampered_irban_is_ignored(): void
    {
        $t = $this->halaman($this->irbanA, ['irban' => (string) $this->b->id, 'tab' => 'gabungan']);
        $html = $t->html();

        // Fase 9.1: Irban has tabs Ringkasan | Detail Realisasi; 'gabungan' falls back to Ringkasan ('irban').
        $t->assertSet('tab', 'irban')->assertSee('Rekap Saya')->assertSee('Ringkasan')->assertSee('Irban A')->assertDontSee('Irban B')->assertDontSee('Gabungan');
        $b = $this->baris($html);
        $this->assertContains('Total 6 3 50', $b);
        $this->assertNotContains('Total 3 1 33', $b);
        $this->assertContains('REGULER 4 1 3', $b); // A only: s1 S, s2 berjalan (TW I), s3 S (TW II), s5 S (TW IV); s4 is KHUSUS
    }

    public function test_alert_without_disahkan_pkpt(): void
    {
        $this->halaman($this->pimpinan)->assertDontSee('belum disahkan');
        Pkpt::where('tahun', 2026)->update(['status' => 'draf']);
        $this->halaman($this->pimpinan)->assertSee('PKPT 2026 belum disahkan; rencana belum dapat dihitung.');
    }

    public function test_tw_filter_and_invalid_values(): void
    {
        $b = $this->baris($this->halaman($this->pimpinan, ['tw' => '2'])->html());
        $this->assertContains('TW II 1 1 100', $b); // Irban A
        $this->assertContains('TW II 2 1 50', $b);  // Irban B
        $this->assertContains('REGULER 2 0 2', $b);
        $this->assertContains('Jenis Dua 3 2 67 2', $b);
        $this->assertNotContains('TW I 2 1 50', $b);

        $t = $this->halaman($this->pimpinan, ['tw' => '9', 'tahun' => 'abc', 'tab' => 'xyz'])->call('$refresh');
        $t->assertSet('tw', '')->assertSet('tahun', '2026')->assertSet('tab', 'irban');
    }

    public function test_export_xlsx_equals_the_page_numbers(): void
    {
        $r = app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'xlsx', ['tahun' => '2026']);
        $this->assertStringContainsString('rekap-triwulan-2026-rr-klasifikasi-jenis.xlsx', $r->headers->get('content-disposition'));
        $rows = $this->rowsXlsx($r);

        foreach (['Rencana vs Realisasi', 'Per Klasifikasi', 'Per Jenis Penugasan', 'Irban Periode Rencana Realisasi %', 'TW II 3 2 67', 'Gabungan Total 9 4 44', 'REGULER Setahun 6 2 4', 'Jenis Satu Setahun 6 2 33 5', 'Total Setahun 9 4 44 8'] as $needle) {
            $this->assertTrue(collect($rows)->contains(fn ($x) => str_contains($x, $needle)), $needle);
        }
        $this->assertTrue(collect($rows)->contains('Irban A Total 6 3 50'));
        $this->assertTrue(collect($rows)->contains('Irban B Total 3 1 33'));
        $this->halaman($this->pimpinan, ['irban' => (string) $this->a->id])->call('bukaEkspor', 'xlsx')->call('unduhEkspor')->assertFileDownloaded('rekap-triwulan-2026-irban-'.$this->a->kode_nomor.'-rr-klasifikasi-jenis.xlsx');
    }

    /** PRD §14 butir 6: for tw=1 every numeric row the page shows (Per Irban and Gabungan tabs) is in the xlsx for the same filter. */
    public function test_export_xlsx_tw1_equals_the_page_cells(): void
    {
        $halaman = [];
        foreach (['irban', 'gabungan'] as $tab) {
            $halaman = [...$halaman, ...array_filter($this->baris($this->halaman($this->pimpinan, ['tahun' => '2026', 'tw' => '1', 'tab' => $tab])->html()), fn ($r) => preg_match('/\d/', $r))];
        }
        $this->assertContains('TW I 2 1 50', $halaman); // Gabungan TW I (hand-computed in the Gabungan test above)
        $this->assertNotContains('TW II 3 2 67', $halaman);

        $r = app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'xlsx', ['tahun' => '2026', 'tw' => '1']);
        $file = $this->rowsXlsx($r);
        $this->assertSame('Rekap Triwulanan 2026 · TW I', $file[0]);
        $this->assertFalse(collect($file)->contains(fn ($x) => str_contains($x, 'TW II')));

        // Page row "{label} {cells}" ↔ file row "{label|irban} {periode} {cells}": the cells must match exactly at the row end.
        foreach ($halaman as $baris) {
            [$label, $sel] = preg_match('/^(TW I|Total)\s(.*)$/', $baris, $m) ? [$m[1], $m[2]] : [preg_replace('/\s[\d–\s]+$/u', '', $baris), trim(substr($baris, strlen(preg_replace('/\s[\d–\s]+$/u', '', $baris))))];
            $this->assertTrue(collect($file)->contains(fn ($x) => str_contains($x, $label) && str_ends_with($x, ' '.$sel)), $baris);
        }
    }

    public function test_export_pdf(): void
    {
        $r = TestResponse::fromBaseResponse(app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'pdf', ['tahun' => '2026']));
        $r->assertDownload('rekap-triwulan-2026-rr-klasifikasi-jenis.pdf');
        $this->assertStringStartsWith('%PDF', $r->streamedContent());

        $p = app(PembangunRekap::class);
        $html = $p->html($this->pimpinan, $p->filter($this->pimpinan, []));
        foreach (['Rencana vs Realisasi', 'Per Klasifikasi', 'Per Jenis Penugasan', 'Irban A', 'Jenis Satu'] as $teks) {
            $this->assertStringContainsString($teks, $html);
        }
    }

    public function test_irban_export_is_scoped_even_with_a_tampered_filter(): void
    {
        $p = app(PembangunRekap::class);
        $f = $p->filter($this->irbanA, ['tahun' => '2026', 'irban' => (string) $this->b->id]);
        $this->assertSame(['tahun' => 2026, 'tw' => 0, 'irban' => $this->a->id, 'bagian' => ['rr', 'klasifikasi', 'jenis'], 'saring' => ['klasifikasi' => null, 'kode_arsip' => null, 'jenis' => null]], $f);
        $this->assertSame('rekap-triwulan-2026-irban-'.$this->a->kode_nomor.'-rr-klasifikasi-jenis', $p->namaFile($this->irbanA, $f));

        $r = app(Ekspor::class)->minta($this->irbanA, 'rekap', 'xlsx', ['irban' => (string) $this->b->id]);
        $text = json_encode(IOFactory::load($r->getFile()->getPathname())->getActiveSheet()->toArray());
        $this->assertStringContainsString('Irban A', $text);
        $this->assertStringNotContainsString('Irban B', $text);
        $this->assertStringNotContainsString('Gabungan', $text);
    }

    public function test_builder_validates_and_authorizes(): void
    {
        $p = app(PembangunRekap::class);
        $this->assertSame(['tahun' => 2026, 'tw' => 0, 'irban' => null, 'bagian' => ['rr', 'klasifikasi', 'jenis'], 'saring' => ['klasifikasi' => null, 'kode_arsip' => null, 'jenis' => null]],
            $p->filter($this->pimpinan, ['tahun' => 'abc', 'tw' => '7', 'irban' => '999', 'klasifikasi' => '99999', 'jenis' => 'x']));
        $this->assertSame('/pimpinan/rekap', $p->halaman($this->pimpinan));

        $this->expectException(AuthorizationException::class);
        $p->otorisasi(User::factory()->create(['role' => Role::BagianUmum]));
    }

    public function test_export_modal_defaults_per_tab_and_validates(): void
    {
        foreach (['irban' => ['rr', 'klasifikasi', 'jenis'], 'gabungan' => ['rr', 'klasifikasi', 'jenis'], 'detail' => ['detail']] as $tab => $bagian) {
            $this->halaman($this->pimpinan, ['tab' => $tab])->call('bukaEkspor', 'pdf')
                ->assertSet('bagianEkspor', $bagian)->assertSet('formatEkspor', 'pdf')->assertSet('pilihEkspor', true);
        }

        $t = $this->halaman($this->pimpinan)->call('bukaEkspor', 'xlsx')->set('bagianEkspor', [])->call('unduhEkspor');
        $t->assertHasErrors(['bagianEkspor' => 'Pilih minimal satu bagian.'])->assertSet('pilihEkspor', true)->assertNoFileDownloaded();

        $this->halaman($this->pimpinan)->call('bukaEkspor', 'xlsx')->set('bagianEkspor', [['x']])->call('unduhEkspor')
            ->assertHasErrors(['bagianEkspor' => 'Pilih minimal satu bagian.'])->assertNoFileDownloaded();

        $this->halaman($this->pimpinan)->call('bukaEkspor', 'xlsx')->set('bagianEkspor', ['detail'])->call('unduhEkspor')
            ->assertFileDownloaded('rekap-triwulan-2026-detail.xlsx')->assertSet('pilihEkspor', false);

        $this->halaman($this->pimpinan)->call('bukaEkspor', 'csv')->assertSet('pilihEkspor', false)->assertSet('formatEkspor', '');
    }

    public function test_export_modal_default_for_irban_is_the_irban_sections(): void
    {
        $this->halaman($this->irbanA)->call('bukaEkspor', 'pdf')
            ->assertSet('bagianEkspor', ['rr', 'klasifikasi', 'jenis'])->assertSet('formatEkspor', 'pdf')->assertSet('pilihEkspor', true);
    }

    public function test_export_modal_requires_rekap_access(): void
    {
        $this->halaman($this->irbanA)->call('bukaEkspor', 'xlsx')->assertSet('pilihEkspor', true);
        $lain = User::factory()->create(['role' => Role::BagianUmum]);
        Livewire::actingAs($lain)->test(Rekap::class)->assertForbidden();
    }

    /** Rekap Saya lists only the years the irban has SPT or PKPT items in (plus the current and selected year), not other irbans' years. */
    public function test_tahun_list_of_an_irban_is_limited_to_its_own_years(): void
    {
        $irbanB = User::factory()->create(['role' => Role::Irban, 'irban_id' => $this->b->id]);
        Spt::where('irban_id', $this->b->id)->where('tanggal_mulai', 'like', '2025-12-01%')->update(['tahun' => 2025]); // s11 sits in 2025

        // Fixture: B's only 2025 SPT (s11) and the Draf 2027 PKPT item of A.
        $this->assertSame([2027, 2026], array_keys($this->halaman($this->irbanA)->viewData('tahunList')));
        $this->assertSame([2026, 2025], array_keys($this->halaman($irbanB)->viewData('tahunList')));
        $this->assertSame([2027, 2026, 2025], array_keys($this->halaman($this->pimpinan)->viewData('tahunList'))); // control: Pimpinan sees all
        $this->assertContains(2031, array_keys($this->halaman($this->irbanA, ['tahun' => '2031'])->viewData('tahunList'))); // the selected year stays
    }

    public function test_pimpinan_can_filter_by_irban_with_a_select_and_irban_has_none(): void
    {
        $t = $this->halaman($this->pimpinan);
        $t->assertSeeHtml('id="rekap-irban"')->assertSee('Semua irban');
        $this->assertSame([$this->a->id => 'Irban A', $this->b->id => 'Irban B'], $t->viewData('irbanOptions'));

        $t->set('irban', (string) $this->b->id);
        $panel = substr($t->html(), strpos($t->html(), 'id="rekap-panel"'));
        $this->assertStringContainsString('Irban B', $panel);
        $this->assertStringNotContainsString('Irban A', $panel);
        $t->call('bukaEkspor', 'xlsx')->set('bagianEkspor', ['rr'])->call('unduhEkspor')
            ->assertFileDownloaded('rekap-triwulan-2026-irban-'.$this->b->kode_nomor.'-rr.xlsx');

        $this->halaman($this->irbanA)->assertDontSeeHtml('id="rekap-irban"');
    }

    public function test_detail_tab_does_not_run_the_recap_calculation(): void
    {
        $dihitung = new \ArrayObject;
        $this->app->bind(RekapTriwulan::class, fn () => new class($dihitung, app(Triwulan::class)) extends RekapTriwulan
        {
            public function __construct(private \ArrayObject $dihitung, Triwulan $tw)
            {
                parent::__construct($tw);
            }

            public function hitung(int $tahun, ?int $irbanId = null, array $saring = []): array
            {
                $this->dihitung[] = $tahun;

                return parent::hitung($tahun, $irbanId, $saring);
            }
        });

        $this->halaman($this->pimpinan)->assertSet('tab', 'irban');
        $this->assertCount(1, $dihitung); // control: the summary tab calculates

        $dihitung->exchangeArray([]);
        $t = $this->halaman($this->pimpinan);
        $dihitung->exchangeArray([]); // the first render was the summary tab
        $t->set('tab', 'detail')->assertSee('Detail Realisasi')->assertDontSee('belum disahkan');
        $t->set('tahun', '2031')->assertSee('PKPT 2031 belum disahkan');
        $this->assertCount(0, $dihitung);
    }

    /** 1.3 Task 2: "–" and the note only when the rule hides the rencana; numbers are those of RekapTriwulanTest (fixture without extra SPT). */
    public function test_saring_di_layar_tanda_dan_catatan_hanya_bila_relevan(): void
    {
        $c1 = RekapTriwulan::CATATAN_TANPA_RENCANA;
        $polos = $this->halaman($this->pimpinan, ['tab' => 'gabungan']);
        $polos->assertDontSee($c1)->assertDontSee(RekapTriwulan::CATATAN_RENCANA_GABUNG)->assertSee('Filter lainnya')
            ->assertSeeHtml('id="rekap-klasifikasi"')->assertSeeHtml('id="rekap-kode_arsip"')->assertSeeHtml('id="rekap-jenis"')
            ->assertSee('Semua klasifikasi')->assertSee('Semua kode arsip')->assertSee('Semua jenis');

        // KHUSUS (not wajib): KHUSUS SPT have no item → realisasi 0; Per Klasifikasi KHUSUS s4 S + s10 B; Per Jenis jumlah J1 s4, J2 s10.
        $t = $this->halaman($this->pimpinan, ['tab' => 'gabungan', 'klasifikasi' => (string) $this->k]);
        $t->assertSee($c1);
        $b = $this->baris($t->html());
        foreach (['TW I – 0 –', 'TW IV – 0 –', 'Total – 0 –', 'KHUSUS 2 1 1', 'REGULER 0 0 0', 'Jenis Satu – 0 – 1', 'Jenis Dua – 0 – 1', 'Total – 0 – 2'] as $r) {
            $this->assertContains($r, $b, $r);
        }
        $this->assertSame(2, substr_count($t->html(), $c1)); // under Rencana vs Realisasi and under Per Jenis Penugasan

        // Jenis J2: rencana a2 + b1 = 3 in TW II, realisasi s3 + s6 → 67%; no note.
        $t = $this->halaman($this->pimpinan, ['tab' => 'gabungan', 'jenis' => (string) $this->j2->id]);
        $t->assertDontSee($c1);
        $b = $this->baris($t->html());
        foreach (['TW I 0 0 –', 'TW II 3 2 67', 'Total 3 2 67', 'Jenis Dua 3 2 67 3', 'Jenis Satu 0 0 – 0'] as $r) {
            $this->assertContains($r, $b, $r);
        }

        // C2: two wajib klasifikasi (one inactive) → full rencana with the C2 note.
        Klasifikasi::whereKey($this->k)->update(['wajib_item_pkpt' => true, 'aktif' => false]);
        $t = $this->halaman($this->pimpinan, ['tab' => 'gabungan', 'klasifikasi' => (string) $this->r]);
        $t->assertSee(RekapTriwulan::CATATAN_RENCANA_GABUNG)->assertDontSee($c1);
        $this->assertContains('Total 9 4 44', $this->baris($t->html()));
    }

    public function test_saring_url_divalidasi_ditulis_balik_dan_atur_ulang(): void
    {
        $t = Livewire::withQueryParams(['klasifikasi' => '99999', 'kode_arsip' => 'abc', 'jenis' => 'lainnya', 'tw' => '2'])->actingAs($this->pimpinan)->test(Rekap::class);
        $t->assertOk()->assertSet('klasifikasi', '')->assertSet('kode_arsip', '')->assertSet('jenis', 'lainnya');
        $this->assertSame(['Lainnya'], array_slice(array_values($t->viewData('saringOpsi')['jenis']), -1));

        $t->set('klasifikasi', (string) $this->k)->assertSet('klasifikasi', (string) $this->k)
            ->call('aturUlang')->assertSet('klasifikasi', '')->assertSet('jenis', '')->assertSet('tw', '')->assertSet('tahun', '2026');
    }

    /** Review Focus 1: an Irban tampering `irban=` together with the new filters stays on its own irban; fictitious ids are ignored without a 500. */
    public function test_irban_dengan_irban_lain_dan_saring_tetap_irbannya(): void
    {
        $t = Livewire::withQueryParams(['irban' => (string) $this->b->id, 'jenis' => 'lainnya', 'klasifikasi' => '99999'])->actingAs($this->irbanA)->test(Rekap::class);
        $t->assertOk()->assertSet('klasifikasi', '')->assertSet('jenis', 'lainnya')->assertSee('Irban A')->assertDontSee('Irban B');

        // REGULER (wajib): A's numbers only — rencana 6, realisasi s1 s3 s5 → Total 6 3 50; never B's Total 3 1 33.
        $t = Livewire::withQueryParams(['irban' => (string) $this->b->id, 'klasifikasi' => (string) $this->r])->actingAs($this->irbanA)->test(Rekap::class);
        $b = $this->baris($t->html());
        $this->assertContains('Total 6 3 50', $b);
        $this->assertNotContains('Total 3 1 33', $b);

        $p = app(PembangunRekap::class);
        $f = $p->filter($this->irbanA, ['irban' => (string) $this->b->id, 'klasifikasi' => (string) $this->r, 'jenis' => 'lainnya', 'kode_arsip' => '0']);
        $this->assertSame($this->a->id, $f['irban']);
        $this->assertSame(['klasifikasi' => $this->r, 'kode_arsip' => null, 'jenis' => 'lainnya'], $f['saring']);
    }

    public function test_ekspor_dari_halaman_membawa_saring(): void
    {
        $t = $this->halaman($this->pimpinan, ['klasifikasi' => (string) $this->k, 'jenis' => (string) $this->j2->id]);
        $t->call('bukaEkspor', 'xlsx')->call('unduhEkspor')->assertFileDownloaded('rekap-triwulan-2026-klasifikasi-khusus-jenis-jenis-dua-rr-klasifikasi-jenis.xlsx');

        $r = app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'xlsx', ['tahun' => '2026', 'klasifikasi' => (string) $this->k, 'jenis' => (string) $this->j2->id]);
        $rows = $this->rowsXlsx($r);
        $this->assertSame('Rekap Triwulanan 2026 · Klasifikasi: KHUSUS · Jenis: Jenis Dua', $rows[0]);
        // KHUSUS + J2: s10 (B, berjalan, TW IV) only.
        $this->assertContains('Gabungan Total – 0 –', $rows);
        $this->assertContains('Jenis Dua Setahun – 0 – 1', $rows);
        $this->assertContains(RekapTriwulan::CATATAN_TANPA_RENCANA, $rows);
    }
}
