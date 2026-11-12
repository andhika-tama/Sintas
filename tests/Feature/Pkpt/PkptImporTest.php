<?php

namespace Tests\Feature\Pkpt;

use App\Enums\Role;
use App\Livewire\Admin\PkptImpor as Page;
use App\Models\Aktivitas;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\User;
use App\Services\Import\PkptItemImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Impor\PeriksaUnduhanExcel;
use Tests\TestCase;

class PkptImporTest extends TestCase
{
    use PeriksaUnduhanExcel, RefreshDatabase;

    private Pkpt $pkpt;

    private Irban $irban;

    private JenisPenugasan $jenis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->pkpt = Pkpt::factory()->create(['tahun' => 2026]);
        $this->irban = Irban::factory()->create(['nama' => 'Irban I', 'kode_nomor' => 'IRBAN-1']);
        $this->jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu Laporan']);
    }

    /** Numeric PHP values are written as numeric cells (Excel date serials); everything else as text. */
    private function xlsx(array $rows, array $header = PkptItemImportParser::HEADERS): string
    {
        $s = new Spreadsheet;
        $sheet = $s->getActiveSheet();
        foreach ([$header, ...$rows] as $r => $row) {
            foreach (array_values($row) as $c => $v) {
                is_int($v) ? $sheet->setCellValueExplicit([$c + 1, $r + 1], $v, 'n') : $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $v, 's');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        IOFactory::createWriter($s, 'Xlsx')->save($path);

        return $path;
    }

    private function row(array $o = []): array
    {
        return array_replace(['Irban I', 'Reviu Laporan', '02/03/2026', '20/03/2026', 'Ket', '3'], $o);
    }

    private function parse(array $rows): array
    {
        $p = new PkptItemImportParser;

        return $p->analyze($this->pkpt, $p->read($this->xlsx($rows)));
    }

    private function upload(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('item.xlsx', file_get_contents($path));
    }

    public function test_parser_rules(): void
    {
        $r = $this->parse([
            $this->row(),                                   // 2 valid by nama
            $this->row([' irban-1 ', 'REVIU laporan ']),    // 3 by kode, case-insensitive (dup of 2)
            $this->row([0 => 'Irban VI']),                  // 4
            $this->row([1 => 'Reviu X']),                   // 5
            $this->row([2 => '2026-04-01', 3 => '2026-04-10']), // 6 ISO
            $this->row([2 => 46113, 3 => 46122]),           // 7 serial 2026-04-01..10 (dup of 6)
            $this->row([2 => '1 April']),                   // 8 bad format
            $this->row([2 => '25/03/2026', 3 => '20/03/2026']), // 9 order
            $this->row([2 => '01/12/2025', 3 => '05/12/2025']), // 10 outside year
            $this->row([5 => '1000']),                      // 11 target high
            $this->row([5 => '0']),                         // 12 target low
            $this->row([5 => '', 4 => '']),                 // 13 empty target -> null
            $this->row([2 => '31/02/2026']),                // 14 impossible date
        ]);
        $this->assertSame(['valid', 'warning', 'error', 'error', 'valid', 'warning', 'error', 'error', 'error', 'error', 'error', 'warning', 'error'], array_column($r, 'status'));
        $this->assertSame(range(2, 14), array_column($r, 'baris'));
        $this->assertSame('Irban "Irban VI" tidak ditemukan.', $r[2]['errors'][0]['pesan']);
        $this->assertSame('Jenis penugasan "Reviu X" tidak ditemukan.', $r[3]['errors'][0]['pesan']);
        $this->assertSame($this->irban->id, $r[1]['data']['irban_id']);
        $this->assertSame('2026-04-01', $r[4]['data']['tanggal_mulai']);
        $this->assertSame('2026-04-01', $r[5]['data']['tanggal_mulai']);
        $this->assertSame('2026-04-10', $r[5]['data']['tanggal_selesai']);
        $this->assertSame('Item yang sama sudah ada.', $r[1]['warning']);
        $this->assertSame('Format tanggal tidak dikenali (gunakan dd/mm/yyyy).', $r[6]['errors'][0]['pesan']);
        $this->assertSame('Tanggal selesai tidak boleh sebelum tanggal mulai.', $r[7]['errors'][0]['pesan']);
        $this->assertSame('Tanggal harus berada di tahun 2026.', $r[8]['errors'][0]['pesan']);
        $this->assertSame('Target SPT harus berupa angka 1 sampai 999.', $r[9]['errors'][0]['pesan']);
        $this->assertSame('target_spt', $r[10]['errors'][0]['kolom']);
        $this->assertSame('Item yang sama sudah ada.', $r[11]['warning']); // same as row 2, target ignored
        $this->assertSame('Format tanggal tidak dikenali (gunakan dd/mm/yyyy).', $r[12]['errors'][0]['pesan']);
    }

    public function test_duplicate_of_existing_item_is_a_warning(): void
    {
        PkptItem::factory()->create(['pkpt_id' => $this->pkpt->id, 'irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->jenis->id,
            'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']);
        $r = $this->parse([$this->row()]);
        $this->assertSame('warning', $r[0]['status']);
    }

    public function test_blank_fields_are_errors(): void
    {
        $r = $this->parse([$this->row([0 => '', 1 => '', 2 => ''])]);
        $this->assertSame(['Irban wajib diisi.', 'Jenis penugasan wajib diisi.', 'Tanggal mulai wajib diisi.'], array_column($r[0]['errors'], 'pesan'));
    }

    public function test_missing_header_and_row_cap(): void
    {
        try {
            (new PkptItemImportParser)->read($this->xlsx([['x']], ['irban']));
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('jenis_penugasan', $e->getMessage());
        }
        $this->expectExceptionMessage('maksimal 2000 baris');
        (new PkptItemImportParser)->read($this->xlsx(array_fill(0, 2001, $this->row())));
    }

    public function test_happy_path_appends_items(): void
    {
        $admin = User::factory()->admin()->create();
        $path = $this->xlsx([$this->row(), $this->row([2 => 46113, 3 => 46122, 4 => '', 5 => ''])]);

        Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])
            ->set('file', $this->upload($path))
            ->assertSet('step', 2)->assertSee('2 valid')->assertSee('0 galat')
            ->call('impor')->assertSet('step', 3)->assertSet('diimpor', 2);

        $this->assertSame(2, $this->pkpt->items()->count());
        $item = $this->pkpt->items()->orderBy('tanggal_mulai')->first();
        $this->assertSame('2026-03-02', $item->tanggal_mulai->toDateString());
        $this->assertSame(3, $item->target_spt);
        $this->assertNull($this->pkpt->items()->orderByDesc('tanggal_mulai')->first()->target_spt);
    }

    public function test_errors_block_unless_skipped(): void
    {
        $admin = User::factory()->admin()->create();
        $path = $this->xlsx([$this->row(), $this->row([0 => 'Irban VI'])]);
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($path))
            ->assertSee('1 galat')->assertSee('Baris 3');
        $c->call('impor')->assertSet('step', 2);
        $this->assertSame(0, $this->pkpt->items()->count());
        $c->set('lewati', true)->call('impor')->assertSet('step', 3)->assertSet('diimpor', 1);
    }

    public function test_duplicates_skipped_by_default_and_importable_when_unchecked(): void
    {
        $admin = User::factory()->admin()->create();
        $path = $this->xlsx([$this->row(), $this->row()]);
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($path))
            ->assertSet('lewatiDuplikat', true)->assertSee('1 peringatan')->assertSee('Baris 3:')->assertSee('Item yang sama sudah ada.');
        $c->call('impor')->assertSet('diimpor', 1);
        $this->assertSame(1, $this->pkpt->items()->count());

        $c->call('ulangi')->set('file', $this->upload($path)); // now both rows duplicate the stored one
        $c->set('lewatiDuplikat', false)->call('impor')->assertSet('diimpor', 2);
        $this->assertSame(3, $this->pkpt->items()->count());
    }

    public function test_disahkan_pkpt_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2025]);
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $pkpt])
            ->assertSee('PKPT sudah disahkan')->assertDontSee('Berkas Excel (.xlsx)');
        $c->set('file', $this->upload($this->xlsx([$this->row([2 => '02/03/2025', 3 => '20/03/2025'])])))->assertSet('step', 1);

        // Tampered state: a PKPT that was sahkan'd after the preview must not accept items.
        $draf = Pkpt::factory()->create(['tahun' => 2024]);
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $draf])
            ->set('file', $this->upload($this->xlsx([$this->row([2 => '02/03/2024', 3 => '20/03/2024'])])))->assertSet('step', 2);
        $draf->forceFill(['status' => 'disahkan'])->save();
        $c->call('impor')->assertSet('step', 2);
        $this->assertSame(0, $draf->items()->count());
    }

    public function test_downloads(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->call('unduhTemplat')->assertFileDownloaded('templat-item-pkpt.xlsx');
        $this->assertSame(['irban', 'jenis_penugasan', 'tanggal_mulai', 'tanggal_selesai', 'keterangan', 'target_spt'], PkptItemImportParser::HEADERS);
        Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])
            ->set('file', $this->upload($this->xlsx([$this->row([0 => 'zzz'])])))
            ->call('unduhGalat')->assertFileDownloaded('galat-impor-pkpt.xlsx');
    }

    public function test_templat_dan_galat_berlebar_dan_berheader_mint(): void
    {
        $admin = User::factory()->admin()->create();
        $s = $this->bacaUnduhan(Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->call('unduhTemplat'));
        $this->periksaHeaderMint($s, count(PkptItemImportParser::HEADERS));
        $this->periksaLebar($s, ['A' => 7, 'B' => 17, 'C' => 15, 'D' => 17, 'E' => 40, 'F' => 12]);

        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])
            ->set('file', $this->upload($this->xlsx([$this->row([0 => 'zzz'])])))->call('unduhGalat');
        $this->periksaBerkasGalat($this->bacaUnduhan($c));
    }

    public function test_non_admin_forbidden(): void
    {
        $this->actingAs(User::factory()->irban()->create())->get("/admin/pkpt/{$this->pkpt->id}/impor")->assertForbidden();
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class, ['pkpt' => $this->pkpt])->assertForbidden();

        $admin = User::factory()->admin()->create();
        foreach (['impor', 'unduhTemplat', 'unduhGalat', 'ulangi'] as $method) {
            $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($this->xlsx([$this->row()])));
            $admin->role = Role::Irban;
            $c->call($method)->assertForbidden();
            $admin->role = Role::Admin;
        }
        $this->assertSame(0, $this->pkpt->items()->count());
    }

    public function test_raw_rusak_dilaporkan_sebagai_galat_baris(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($this->xlsx([$this->row()])))->assertSet('step', 2);
        $c->set('raw', [['baris' => 2], 'bukan-array', ['baris' => 4, 'irban' => ['x'], 'tanggal_mulai' => null, 'target_spt' => 5, 'keterangan' => new \stdClass]])
            ->assertSet('step', 2)->assertSee('3 galat')->call('impor')->assertSet('step', 2);
        $this->assertSame(0, $this->pkpt->items()->count());
    }

    public function test_berkas_hanya_judul_ditolak(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Page::class, ['pkpt' => $this->pkpt])
            ->set('file', $this->upload($this->xlsx([])))->assertSet('step', 1)->assertHasErrors('file');
    }

    public function test_tanpa_baris_yang_dapat_diimpor_ditolak_server(): void
    {
        $admin = User::factory()->admin()->create();
        // Semua galat + lewati.
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])
            ->set('file', $this->upload($this->xlsx([$this->row([0 => 'zzz']), $this->row([0 => 'yyy'])])))->set('lewati', true);
        $c->call('impor')->assertSet('step', 2)->assertDispatched('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');
        // Hanya duplikat yang dilewati.
        PkptItem::factory()->create(['pkpt_id' => $this->pkpt->id, 'irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->jenis->id,
            'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']);
        $c = Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($this->xlsx([$this->row()])))
            ->assertSee('1 peringatan');
        $c->call('impor')->assertSet('step', 2)->assertDispatched('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');
        $this->assertSame(1, $this->pkpt->items()->count());
        $this->assertNull(Aktivitas::imporTerakhir());
        $c->set('lewatiDuplikat', false)->call('impor')->assertSet('step', 3)->assertSet('diimpor', 1); // kontrol
    }

    public function test_analisis_tidak_menjalankan_query_per_baris(): void
    {
        $hitung = function (int $baris) {
            $raw = array_map(fn ($i) => ['baris' => $i + 2, 'irban' => 'Irban I', 'jenis_penugasan' => 'Reviu Laporan', 'tanggal_mulai' => '02/03/2026',
                'tanggal_selesai' => '20/03/2026', 'keterangan' => "K{$i}", 'target_spt' => '2'], range(0, $baris - 1));
            DB::enableQueryLog();
            $hasil = (new PkptItemImportParser)->analyze($this->pkpt, $raw);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();
            $this->assertCount($baris, $hasil);
            $this->assertNotContains('error', array_column($hasil, 'status')); // kontrol: baris benar-benar divalidasi

            return $n;
        };
        $sedikit = $hitung(2);
        $this->assertSame($sedikit, $hitung(30));
    }

    public function test_ringkasan_impor_dicatat_atas_nama_pengguna(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Page::class, ['pkpt' => $this->pkpt])->set('file', $this->upload($this->xlsx([$this->row()])))->call('impor');
        $this->assertSame($admin->id, Aktivitas::imporTerakhir()->causer_id);
    }
}
