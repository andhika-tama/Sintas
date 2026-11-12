<?php

namespace Tests\Feature\MasterData;

use App\Enums\Role;
use App\Livewire\Admin\PegawaiImpor as Page;
use App\Models\Aktivitas;
use App\Models\Pegawai;
use App\Models\User;
use App\Services\Import\PegawaiImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Impor\PeriksaUnduhanExcel;
use Tests\TestCase;

class PegawaiImporTest extends TestCase
{
    use PeriksaUnduhanExcel, RefreshDatabase;

    private const H = ['nama_bergelar', 'nip', 'pangkat', 'golongan', 'jabatan', 'aktif'];

    /** @var list<string> */
    private array $berkasSementara = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        array_map(fn ($f) => @unlink($f), $this->berkasSementara);
        parent::tearDown();
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows, array $header = self::H): string
    {
        $s = new Spreadsheet;
        $sheet = $s->getActiveSheet();
        foreach ([$header, ...$rows] as $r => $row) {
            foreach (array_values($row) as $c => $v) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $v, 's');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        IOFactory::createWriter($s, 'Xlsx')->save($path);

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pegawai.xlsx', file_get_contents($path));
    }

    private function parse(array $rows): array
    {
        return (new PegawaiImportParser)->parse($this->xlsx($rows));
    }

    private function nip(int $n = 1): string
    {
        return str_pad((string) $n, 18, '1');
    }

    public function test_parser_rules(): void
    {
        Pegawai::factory()->create(['nip' => $this->nip(3)]);
        $r = $this->parse([
            ['Budi, S.H.', $this->nip(1), '', '', '', ''],
            ['Ani', '19870312', '', '', '', 'ya'],
            ['', $this->nip(2), '', '', '', ''],
            ['Cici', $this->nip(3), '', '', '', 'Tidak'],
            ['Dedi', $this->nip(1), '', '', '', ''],
            ['Eka', $this->nip(5), '', '', '', 'mungkin'],
        ]);
        $this->assertSame(['valid', 'error', 'error', 'warning', 'error', 'error'], array_column($r, 'status'));
        $this->assertSame([2, 3, 4, 5, 6, 7], array_column($r, 'baris'));
        $this->assertTrue($r[0]['data']['aktif']);
        $this->assertFalse($r[3]['data']['aktif']);
        $this->assertSame('NIP harus 18 digit angka.', $r[1]['errors'][0]['pesan']);
        $this->assertSame('nip', $r[1]['errors'][0]['kolom']);
        $this->assertSame('nama_bergelar', $r[2]['errors'][0]['kolom']);
        $this->assertStringContainsString('akan diperbarui', $r[3]['warning']);
        $this->assertStringContainsString('baris 2', $r[4]['errors'][0]['pesan']);
        $this->assertSame('aktif', $r[5]['errors'][0]['kolom']);
    }

    public function test_aktif_values(): void
    {
        $r = $this->parse(array_map(fn ($v, $i) => ['N', $this->nip($i + 1), '', '', '', $v],
            ['1', '0', 'YA', 'tidak', 'True', 'false'], range(0, 5)));
        $this->assertSame([true, false, true, false, true, false], array_map(fn ($x) => $x['data']['aktif'], $r));
    }

    public function test_missing_header_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nama_bergelar');
        (new PegawaiImportParser)->parse($this->xlsx([['x']], ['foo']));
    }

    public function test_row_cap(): void
    {
        $rows = array_fill(0, 2001, ['N', $this->nip(1), '', '', '', '']);
        $this->expectExceptionMessage('maksimal 2000 baris');
        (new PegawaiImportParser)->parse($this->xlsx($rows));
    }

    public function test_happy_path_upserts(): void
    {
        $admin = User::factory()->admin()->create();
        Pegawai::factory()->create(['nip' => $this->nip(3), 'nama_bergelar' => 'Lama']);
        $path = $this->xlsx([['Budi', $this->nip(1), 'Penata', 'III/c', 'Auditor', 'ya'], ['Baru', $this->nip(3), '', '', '', '0']]);

        Livewire::actingAs($admin)->test(Page::class)
            ->set('file', $this->upload($path))
            ->assertSet('step', 2)
            ->assertSee('1 valid')->assertSee('1 peringatan')->assertSee('0 galat')
            ->call('impor')
            ->assertSet('step', 3)->assertSet('diimpor', 2);

        $this->assertSame('Budi', Pegawai::where('nip', $this->nip(1))->value('nama_bergelar'));
        $this->assertSame('Baru', Pegawai::where('nip', $this->nip(3))->first()->nama_bergelar);
        $this->assertFalse(Pegawai::where('nip', $this->nip(3))->first()->aktif);
        $this->assertSame(2, Pegawai::count());
    }

    public function test_errors_block_unless_skipped(): void
    {
        $admin = User::factory()->admin()->create();
        $path = $this->xlsx([['Budi', $this->nip(1), '', '', '', ''], ['Bad', '123', '', '', '', '']]);
        $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($path))
            ->assertSee('1 galat')->assertSee('Baris 3');
        $c->call('impor')->assertSet('step', 2);
        $this->assertSame(0, Pegawai::count());
        $c->set('lewati', true)->call('impor')->assertSet('step', 3)->assertSet('diimpor', 1);
        $this->assertSame(1, Pegawai::count());
    }

    public function test_invalid_upload_stays_on_step_1(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Page::class)
            ->set('file', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
            ->assertHasErrors('file')->assertSet('step', 1);
    }

    public function test_downloads(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Page::class)->call('unduhTemplat')->assertFileDownloaded('templat-pegawai.xlsx');
        $path = $this->xlsx([['Bad', '1', '', '', '', '']]);
        Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($path))
            ->call('unduhGalat')->assertFileDownloaded('galat-impor-pegawai.xlsx');
    }

    public function test_templat_dan_galat_berlebar_dan_berheader_mint(): void
    {
        $admin = User::factory()->admin()->create();
        $s = $this->bacaUnduhan(Livewire::actingAs($admin)->test(Page::class)->call('unduhTemplat'));
        $this->periksaHeaderMint($s, count(PegawaiImportParser::HEADERS));
        $this->periksaLebar($s, ['A' => 30, 'B' => 22, 'C' => 20, 'D' => 10, 'E' => 30, 'F' => 28, 'G' => 7]);

        $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($this->xlsx([['Bad', '1', '', '', '', '']])))->call('unduhGalat');
        $this->periksaBerkasGalat($this->bacaUnduhan($c));
    }

    public function test_non_admin_forbidden(): void
    {
        $this->actingAs(User::factory()->irban()->create())->get('/admin/pegawai/impor')->assertForbidden();
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class)->assertForbidden();

        $admin = User::factory()->admin()->create();
        $path = $this->xlsx([['Budi', $this->nip(1), '', '', '', '']]);
        foreach (['impor', 'unduhTemplat', 'unduhGalat', 'ulangi'] as $method) {
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($path));
            $admin->role = Role::Irban;
            $c->call($method)->assertForbidden();
        }
        $this->assertSame(0, Pegawai::count());
    }

    public function test_numeric_nip_cell_is_an_error_and_not_imported(): void
    {
        $s = new Spreadsheet;
        $sh = $s->getActiveSheet();
        foreach (self::H as $c => $h) {
            $sh->setCellValueExplicit([$c + 1, 1], $h, DataType::TYPE_STRING);
        }
        $sh->setCellValueExplicit([1, 2], 'Budi', DataType::TYPE_STRING);
        $sh->setCellValueExplicit([2, 2], '198703121234567890', DataType::TYPE_NUMERIC);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        IOFactory::createWriter($s, 'Xlsx')->save($path);

        $r = (new PegawaiImportParser)->parse($path);
        $this->assertSame('error', $r[0]['status']);
        $this->assertSame('NIP tersimpan sebagai angka; ubah kolom NIP menjadi teks.', $r[0]['errors'][0]['pesan']);

        Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)
            ->set('file', $this->upload($path))->call('impor')->assertSet('step', 2);
        $this->assertSame(0, Pegawai::count());
    }

    public function test_missing_optional_headers_give_empty_values(): void
    {
        $r = (new PegawaiImportParser)->parse($this->xlsx([['Budi', $this->nip(1), 'Auditor']], ['nama_bergelar', 'nip', 'jabatan']));
        $this->assertSame('valid', $r[0]['status']);
        $this->assertSame(['nama' => 'Budi', 'nip' => $this->nip(1), 'pangkat' => '', 'golongan' => '', 'jabatan' => 'Auditor', 'aktif' => true], $r[0]['data']);
    }

    public function test_malformed_raw_is_reported_as_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($this->xlsx([['Budi', $this->nip(1), '', '', '', '']])));
        $c->set('raw', [['baris' => 2], 'bukan-array', ['baris' => 4, 'nama_bergelar' => ['x'], 'nip' => null, 'aktif' => 5]])
            ->assertSet('step', 2)->assertSee('3 galat')->call('impor')->assertSet('step', 2);
        $this->assertSame(0, Pegawai::count());
    }

    public function test_row_cap_is_enforced_on_tampered_raw(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($this->xlsx([['Budi', $this->nip(1), '', '', '', '']])));
        $big = array_fill(0, 2001, ['baris' => 2, 'nama_bergelar' => 'N', 'nip' => $this->nip(1), 'pangkat' => '', 'golongan' => '', 'jabatan' => '', 'aktif' => '']);
        $c->set('raw', $big)->call('impor')->assertSet('step', 1)->assertHasErrors('file');
        $this->assertSame(0, Pegawai::count());
    }

    public function test_import_without_email_column_keeps_stored_email(): void
    {
        Pegawai::factory()->create(['nip' => $this->nip(1), 'email' => 'tetap@example.test']);
        Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)
            ->set('file', $this->upload($this->xlsx([['Baru', $this->nip(1), '', '', '', '']])))
            ->call('impor')->assertSet('diimpor', 1);
        $p = Pegawai::where('nip', $this->nip(1))->first();
        $this->assertSame('Baru', $p->nama_bergelar);
        $this->assertSame('tetap@example.test', $p->email);
    }

    public function test_email_column(): void
    {
        Pegawai::factory()->create(['nip' => $this->nip(8), 'email' => 'ada@example.test']);
        Pegawai::factory()->create(['nip' => $this->nip(9), 'email' => 'sama@example.test']);
        $h = ['nama_bergelar', 'nip', 'pangkat', 'golongan', 'jabatan', 'email', 'aktif'];
        $r = (new PegawaiImportParser)->parse($this->xlsx([
            ['A', $this->nip(1), '', '', '', 'Budi@Example.test', ''],
            ['B', $this->nip(2), '', '', '', 'bukan-email', ''],
            ['C', $this->nip(3), '', '', '', 'budi@example.test', ''],
            ['D', $this->nip(4), '', '', '', 'ada@example.test', ''],
            ['E', $this->nip(9), '', '', '', 'sama@example.test', ''],
            ['F', $this->nip(5), '', '', '', '', ''],
        ], $h));

        $this->assertSame(['valid', 'error', 'error', 'error', 'warning', 'valid'], array_column($r, 'status'));
        $this->assertSame('budi@example.test', $r[0]['data']['email']);
        $this->assertSame(['email', 'Format email tidak valid.'], array_values($r[1]['errors'][0]));
        $this->assertSame(['email', 'Email ganda dengan baris 2.'], array_values($r[2]['errors'][0]));
        $this->assertSame(['email', 'Email sudah dipakai pegawai lain.'], array_values($r[3]['errors'][0]));

        Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)
            ->set('file', $this->upload($this->xlsx([['A', $this->nip(1), '', '', '', 'Budi@Example.test', '']], $h)))
            ->call('impor')->assertSet('diimpor', 1);
        $this->assertSame('budi@example.test', Pegawai::where('nip', $this->nip(1))->value('email'));
    }

    public function test_template_headers_include_email(): void
    {
        $this->assertSame(['nama_bergelar', 'nip', 'pangkat', 'golongan', 'jabatan', 'email', 'aktif'], PegawaiImportParser::HEADERS);
    }

    public function test_email_sudah_dipakai_tanpa_peka_huruf(): void
    {
        Pegawai::factory()->create(['nip' => $this->nip(8), 'email' => 'Ada@Example.test']);
        $h = ['nama_bergelar', 'nip', 'pangkat', 'golongan', 'jabatan', 'email', 'aktif'];
        $r = (new PegawaiImportParser)->parse($this->xlsx([
            ['A', $this->nip(1), '', '', '', 'ada@example.test', ''],
            ['B', $this->nip(2), '', '', '', 'lain@example.test', ''], // kontrol
        ], $h));
        $this->assertSame(['error', 'valid'], array_column($r, 'status'));
        $this->assertSame('Email sudah dipakai pegawai lain.', $r[0]['errors'][0]['pesan']);
    }

    public function test_templat_memformat_kolom_nip_sebagai_teks_seluruh_kolom(): void
    {
        $c = Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)->call('unduhTemplat');
        $path = tempnam(sys_get_temp_dir(), 'tp');
        $this->berkasSementara[] = $path;
        file_put_contents($path, base64_decode($c->effects['download']['content']));
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('@', $sheet->getColumnStyle('B')->getNumberFormat()->getFormatCode());
        $this->assertSame('General', $sheet->getColumnStyle('A')->getNumberFormat()->getFormatCode()); // kontrol: hanya kolom NIP
    }

    public function test_berkas_hanya_judul_ditolak(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)
            ->set('file', $this->upload($this->xlsx([])))->assertSet('step', 1)->assertHasErrors('file');
    }

    public function test_semua_galat_dengan_lewati_ditolak_server(): void
    {
        $c = Livewire::actingAs(User::factory()->admin()->create())->test(Page::class)
            ->set('file', $this->upload($this->xlsx([['Bad', '1', '', '', '', ''], ['Bad2', '2', '', '', '', '']])))
            ->set('lewati', true)->assertSee('2 galat');
        $c->call('impor')->assertSet('step', 2)->assertDispatched('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');
        $this->assertSame(0, Pegawai::count());
        $this->assertNull(Aktivitas::imporTerakhir());
    }

    public function test_ringkasan_impor_dicatat_atas_nama_pengguna(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Livewire::actingAs($admin)->test(Page::class)->set('file', $this->upload($this->xlsx([['Budi', $this->nip(1), '', '', '', '']])))->call('impor');
        $this->assertSame($admin->id, Aktivitas::imporTerakhir()->causer_id);
        $this->assertSame('pegawai', Aktivitas::imporTerakhir()->properties['jenis']);
    }
}
