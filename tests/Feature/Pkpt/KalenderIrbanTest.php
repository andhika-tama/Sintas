<?php

namespace Tests\Feature\Pkpt;

use App\Livewire\Irban\Pkpt as IrbanPage;
use App\Models\Irban;
use App\Models\IrbanWilayah;
use App\Models\JenisPenugasan;
use App\Models\Objek;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\User;
use App\Services\KalenderPkpt;
use Database\Seeders\IrbanSeeder;
use Database\Seeders\JenisPenugasanSeeder;
use Database\Seeders\PkptIrbanIDemo2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Support\JadwalItem;
use Tests\TestCase;

/** Spec Tugas B.5 #4–#5: Kalender view of the Irban "Jadwal PKPT" page. */
class KalenderIrbanTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private Irban $lain;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-21 10:00:00'); // Wed → Okt M3
        $this->seed([IrbanSeeder::class, JenisPenugasanSeeder::class, PkptIrbanIDemo2026Seeder::class]);
        $this->irban = Irban::where('nama', 'Irban I')->first();
        $this->lain = Irban::where('id', '!=', $this->irban->id)->first();
        $this->user = User::factory()->irban()->create();
        $this->user->forceFill(['irban_id' => $this->irban->id])->save();
    }

    private function item(string $mulai): PkptItem
    {
        return PkptItem::where('irban_id', $this->irban->id)->whereDate('tanggal_mulai', $mulai)->firstOrFail();
    }

    public function test_default_is_calendar_and_tabel_renders_the_table(): void
    {
        $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()
            ->assertSee('data-kalender-pkpt', false)->assertSee('Minggu ini')->assertSee('6–10 Apr')
            ->assertSee('1 tim 1 minggu 2 sekolah')->assertDontSee('aria-label="Item PKPT Irban I 2026"', false);

        $this->actingAs($this->user)->get('/irban/pkpt?tampilan=tabel')->assertOk()
            ->assertSee('aria-label="Item PKPT Irban I 2026"', false)->assertDontSee('data-kalender-pkpt', false);

        $this->actingAs($this->user)->get('/irban/pkpt?tampilan=xyz')->assertOk()
            ->assertSee('data-kalender-pkpt', false)->assertDontSee('aria-label="Item PKPT Irban I 2026"', false);

        Livewire::actingAs($this->user)->test(IrbanPage::class)
            ->assertSet('tampilan', 'kalender')
            ->set('tampilan', 'tabel')->assertSee('aria-label="Item PKPT Irban I 2026"', false)
            ->set('tampilan', 'bogus')->assertSet('tampilan', 'kalender')->assertSee('data-kalender-pkpt', false);
    }

    /** Jenis and effective dates are for the table, the cards and the open modal: the calendar view does not eager-load them in the page. */
    public function test_calendar_view_skips_the_work_only_the_table_needs(): void
    {
        $jenisQuery = function (string $tampilan): int {
            $n = 0;
            $t = Livewire::actingAs($this->user)->test(IrbanPage::class)->set('tampilan', $tampilan);
            \DB::listen(function ($q) use (&$n) {
                $n += preg_match('/from ["`]jenis_penugasan["`]/', $q->sql);
            });
            $t->call('$refresh');

            return $n;
        };

        $kalender = $jenisQuery('kalender');
        $tabel = $jenisQuery('tabel');

        $this->assertSame(1, $kalender); // KalenderPkpt reads the jenis itself; the page adds no second load
        $this->assertSame(1, $tabel); // the table has its own eager load
    }

    public function test_only_own_items_and_wilayah(): void
    {
        $jenis = JenisPenugasan::create(['nama' => 'Jenis Rahasia Irban Lain', 'warna' => '#332288', 'warna_teks' => '#FFFFFF']);
        PkptItem::factory()->create(['pkpt_id' => Pkpt::where('tahun', 2026)->value('id'), 'irban_id' => $this->lain->id,
            'jenis_penugasan_id' => $jenis->id, 'tanggal_mulai' => '2026-05-11', 'tanggal_selesai' => '2026-05-15', 'keterangan' => 'Keterangan irban lain']);
        $sendiri = Objek::factory()->create(['nama' => 'Kecamatan Milik Sendiri', 'jenis' => 'kecamatan']);
        $bumd = Objek::factory()->create(['nama' => 'Perumdam Lematang Enim', 'jenis' => 'bumd']);
        $orang = Objek::factory()->create(['nama' => 'Kecamatan Milik Lain', 'jenis' => 'kecamatan']);
        IrbanWilayah::create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'objek_id' => $sendiri->id]);
        IrbanWilayah::create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'objek_id' => $bumd->id]);
        $rsud = Objek::factory()->create(['nama' => 'RSUD Contoh Sehat', 'jenis' => 'rsud']);
        IrbanWilayah::create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'objek_id' => $rsud->id]);
        IrbanWilayah::create(['irban_id' => $this->lain->id, 'tahun' => 2026, 'objek_id' => $orang->id]);

        $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()
            ->assertSee('Wilayah Pengawasan')->assertSee('Kecamatan Milik Sendiri')->assertSee('Perumdam Lematang Enim')
            ->assertSeeInOrder(['Kecamatan', 'Dinas/Badan', 'BUMD', 'Perumdam Lematang Enim', 'RSUD', '(1)', 'RSUD Contoh Sehat'])
            ->assertSee('Puskesmas, Desa, BUMDes, dan Sekolah mengikuti wilayah kecamatan.')
            ->assertDontSee('RSUD, Puskesmas')
            ->assertDontSee('Kecamatan Milik Lain')->assertDontSee('Jenis Rahasia Irban Lain')->assertDontSee('Keterangan irban lain');
    }

    /** 1.2 Task 7: "Ajukan Perubahan Penugasan" replaces "Usulkan Perubahan" in the calendar detail and the table; no "Usulkan item baru" (K7). */
    public function test_ajukan_perubahan_penugasan_in_calendar_and_table(): void
    {
        $id = $this->item('2026-06-29')->id;
        foreach (['kalender', 'tabel'] as $tampilan) {
            $this->actingAs($this->user)->get('/irban/pkpt?tampilan='.$tampilan)->assertOk()
                ->assertDontSee('Ajukan Revisi')->assertSee('Ajukan Perubahan Penugasan')->assertSee('wire:click="bukaPerubahan('.$id.')"', false)
                ->assertDontSee('Usulkan');
        }
    }

    public function test_two_calendars_on_one_page_share_no_id_and_the_popover_is_non_modal(): void
    {
        $kalender = app(KalenderPkpt::class)->untuk(2026, $this->irban->id);
        $html = Blade::render(
            '<x-app.kalender-pkpt :kalender="$k" :tahun="2026" /><x-app.kalender-pkpt :kalender="$k" :tahun="2026" id="kedua" />', ['k' => $kalender]);

        $ganda = function (string $html) {
            preg_match_all('/\sid="([^"]+)"/', $html, $m);

            return array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));
        };
        $this->assertSame([], $ganda($html), 'id ganda');
        // Control: the same check does flag duplicates when both instances keep the default id (otherwise it would pass vacuously).
        $this->assertContains('judul-kalender', $ganda(Blade::render(
            '<x-app.kalender-pkpt :kalender="$k" :tahun="2026" /><x-app.kalender-pkpt :kalender="$k" :tahun="2026" />', ['k' => $kalender])));
        // Control: the second instance really renders ids of its own.
        $this->assertStringContainsString('id="judul-kedua"', $html);
        $this->assertStringContainsString('id="judul-kalender"', $html);
        $this->assertStringContainsString('id="kedua-bulan-g-1"', $html);
        // The popover leaves the page behind it interactive, so it must not claim to be modal.
        $this->assertSame(2, substr_count($html, 'role="dialog" aria-modal="false"'));
        $this->assertStringNotContainsString('id="detail-p-', $html);
    }

    public function test_cards_carry_the_shared_focus_target_so_the_host_does_not_know_data_kartu(): void
    {
        $id = $this->item('2026-06-29')->id;
        $html = $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*data-kartu="'.$id.'"[^>]*data-perubahan-pemicu="'.$id.'"/', $html);
        $this->assertStringContainsString("[data-perubahan-pemicu='\${id}']", $html);
        $this->assertStringNotContainsString("[data-kartu='\${id}']", $html);
    }

    /** Fase 9.2 Task 9: an item added by an approved package carries "Tambahan · Perubahan ke-N" on the card and in the detail. */
    public function test_tambahan_label_and_semula_in_calendar(): void
    {
        $p = PkptPerubahan::factory()->disetujui(3)->create(['pkpt_id' => Pkpt::where('tahun', 2026)->value('id')]);
        $baru = $this->item('2026-06-29');
        $baru->update(['ditambahkan_perubahan_id' => $p->id]);
        $geser = PkptItem::where('irban_id', $this->irban->id)->whereKeyNot($baru->id)->orderBy('tanggal_mulai')->firstOrFail();
        JadwalItem::geser($geser, '2026-11-02', '2026-11-06');

        $html = $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()->getContent();
        // Card(s) + popover detail + drawer detail.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'Tambahan · Perubahan ke-3'));
        $this->assertStringContainsString('Jadwal semula', $html);
        $this->assertStringContainsString('2–6 November 2026', $html);
    }

    public function test_moved_item_shows_marker_and_semula_and_dihapus_is_hidden(): void
    {
        $item = $this->item('2026-06-29');
        JadwalItem::geser($item, '2026-11-02', '2026-11-06');
        PkptItem::factory()->dihapus()->create(['pkpt_id' => $item->pkpt_id, 'irban_id' => $this->irban->id, 'keterangan' => 'Item yang dihapus']);

        $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()
            ->assertSee('Jadwal direvisi')->assertSee('29 Juni – 3 Juli 2026')->assertSee('2–6 November 2026')->assertDontSee('Item yang dihapus');
        $this->actingAs($this->user)->get('/irban/pkpt?tampilan=tabel')->assertOk()
            ->assertSee('semula 29 Juni – 3 Juli 2026')->assertDontSee('Item yang dihapus');
    }

    public function test_legend_lists_present_jenis_only(): void
    {
        $absen = JenisPenugasan::create(['nama' => 'Jenis Tanpa Item', 'warna' => '#332288', 'warna_teks' => '#FFFFFF']);

        $html = $this->actingAs($this->user)->get('/irban/pkpt')->assertOk()->getContent();
        preg_match('/aria-label="Legenda jenis penugasan".*?<\/ul>/s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('Monev', $m[0]);
        $this->assertStringContainsString('Audit Ketaatan OPD', $m[0]);
        $this->assertStringNotContainsString($absen->nama, $m[0]);
        $this->assertSame(PkptItem::where('irban_id', $this->irban->id)->distinct()->count('jenis_penugasan_id'), substr_count($m[0], '<li'));
    }
}
