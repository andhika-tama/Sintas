<?php

namespace Tests\Feature\Ekspor;

use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Enums\SumberSpt;
use App\Exports\RekapTahunanSheet;
use App\Livewire\Admin\Klasifikasi as KlasifikasiPage;
use App\Livewire\BagianUmum\Register;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\Pegawai;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunRekapTahunan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Spec 1.1.4 Task 4: Rekap Tahunan workbook (stambook Bagian Umum) and the klasifikasi colour. */
class RekapTahunanTest extends TestCase
{
    use RefreshDatabase;

    private User $bu;

    private Irban $irban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $this->bu = User::factory()->create(['role' => Role::BagianUmum]);
        $this->irban = Irban::factory()->create(['kode_nomor' => 'INSPEKTORAT-II']);
    }

    private function spt(int $nomor, string $tanggal, string $mulai, string $selesai, string $klasifikasi = 'REGULER', array $o = []): Spt
    {
        return Spt::factory()->diterima()->create($o + [
            'irban_id' => $this->irban->id, 'klasifikasi_id' => Klasifikasi::where('kode', $klasifikasi)->value('id'),
            'nomor_urut' => $nomor, 'nomor_lengkap' => "700/{$nomor}/INSPEKTORAT-II/SPT/2025", 'tahun' => 2025, 'tanggal_spt' => $tanggal,
            'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'uraian_untuk' => "Uraian {$nomor}",
        ]);
    }

    /** The six cases of the spec in 2025, plus a 2026 SPT that must stay out. */
    private function data(): array
    {
        $ppj = Pegawai::factory()->create(['nama_bergelar' => 'Drs. Penanggung Jawab, M.Si.', 'nip' => '196501011990031001']);
        $ketua = Pegawai::factory()->create(['nama_bergelar' => 'Ketua Tim, S.E.', 'nip' => '197001011995031002']);
        $jan = $this->spt(28, '2025-01-24', '2025-01-28', '2025-02-03', 'REGULER', ['ppj_pegawai_id' => $ppj->id, 'nomor_sppd' => '090/12/SPPD/2025']);
        // The PPJ also sits in the team: written once.
        foreach ([$ketua, $ppj] as $i => $p) {
            $jan->personel()->create(['pegawai_id' => $p->id, 'peran' => $i ? 'anggota' : 'ketua', 'urutan' => $i + 1, 'sumber' => 'tambahan']);
        }
        $feb = $this->spt(12, '2025-02-03', '2025-02-03', '2025-02-07', 'KHUSUS');
        $sehari = $this->spt(13, '2025-02-10', '2025-02-10', '2025-02-10', 'DIKLAT', ['nomor_sppd' => '=1+1']);
        $batal = $this->spt(29, '2025-01-27', '2025-01-28', '2025-01-30', 'REGULER', ['status_pengajuan' => StatusPengajuan::Dibatalkan]);
        $batal->riwayat()->create(['kejadian' => KejadianSpt::DIBATALKAN, 'alasan' => 'Salah ketik perihal', 'oleh' => $this->bu->id]);
        $manual = $this->spt(30, '2025-01-30', '2025-01-31', '2025-01-31', 'LAIN_LAIN', ['sumber' => SumberSpt::Manual]);
        $desember = $this->spt(99, '2025-12-20', '2025-12-29', '2026-01-02', 'LUAR_DAERAH');
        $this->spt(1, '2026-01-05', '2026-01-05', '2026-01-06', 'REGULER', ['tahun' => 2026, 'nomor_lengkap' => '700/1/INSPEKTORAT-II/SPT/2026']);

        return compact('jan', 'feb', 'sehari', 'batal', 'manual', 'desember');
    }

    private function buku(int $tahun = 2025): Spreadsheet
    {
        $p = new PembangunRekapTahunan;
        $tmp = tempnam(sys_get_temp_dir(), 'rt').'.xlsx';
        file_put_contents($tmp, Excel::raw($p->export($this->bu, $p->filter($this->bu, ['tahun' => $tahun])), \Maatwebsite\Excel\Excel::XLSX));
        if ($simpan = getenv('SAMPLE_REKAP_TAHUNAN')) {
            copy($tmp, $simpan);
        }
        $buku = IOFactory::load($tmp);
        unlink($tmp);

        return $buku;
    }

    public function test_initial_colours_follow_the_bagian_umum_file(): void
    {
        $this->assertEquals(['LAIN_LAIN' => 'FFE699', 'KHUSUS' => 'F4B084', 'REGULER' => 'BDD7EE', 'DIKLAT' => 'A9D08E', 'LUAR_DAERAH' => 'FF99FF'],
            Klasifikasi::pluck('warna', 'kode')->all());
    }

    public function test_twelve_sheets_blocks_ribbons_and_empty_months(): void
    {
        $d = $this->data();
        $buku = $this->buku();

        $this->assertSame(PembangunRekapTahunan::BULAN, $buku->getSheetNames());

        $jan = $buku->getSheetByName('JANUARI');
        $this->assertSame('Rekap SPT Bulan Januari 2025', $jan->getCell('A1')->getValue());
        $this->assertSame(['No SPT', 'No SPPD', 'Tanggal Terbit', 'Dalam Rangka', 'Nama Tim', null, 'REGULER', 'KHUSUS', 'DIKLAT', 'LUAR DAERAH', 'LAIN-LAIN'],
            $jan->rangeToArray('A2:K2')[0]);
        $this->assertSame('CCC0DA', $jan->getStyle('A2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('BDD7EE', $jan->getStyle('G2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFE699', $jan->getStyle('K2')->getFill()->getStartColor()->getRGB());

        // 24 Januari (no. 28) is in JANUARI although Februari holds no. 12; rows are in number order; the 2026 SPT is absent.
        $this->assertSame(['0028', '0029', '0030'], [$jan->getCell('A3')->getValue(), $jan->getCell('A5')->getValue(), $jan->getCell('A7')->getValue()]);
        $this->assertSame(8, $jan->getHighestRow());

        // Block of no. 28: A–D merged over two rows, Nama Tim numbered (PPJ first, once, no NIP or role), ribbon in the REGULER colour.
        foreach (['A3:A4', 'B3:B4', 'C3:C4', 'D3:D4'] as $gabung) {
            $this->assertArrayHasKey($gabung, $jan->getMergeCells());
        }
        $this->assertSame(['0028', '090/12/SPPD/2025', '24 Januari 2025', 'Uraian 28', "1. Drs. Penanggung Jawab, M.Si.\n2. Ketua Tim, S.E."],
            $jan->rangeToArray('A3:E3')[0]);
        $this->assertStringNotContainsString('1965', $jan->getCell('E3')->getValue());
        $this->assertStringNotContainsString('Ketua Tim)', $jan->getCell('E3')->getValue());
        $this->assertSame('28 Januari s.d 03 Februari 2025', $jan->getCell('E4')->getValue());
        $this->assertSame('BDD7EE', $jan->getStyle('E4')->getFill()->getStartColor()->getRGB());
        $this->assertSame('151C1A', $jan->getStyle('E4')->getFont()->getColor()->getRGB()); // light colour: dark text, as in the Admin preview
        $this->assertSame(Border::BORDER_THICK, $jan->getStyle('E3')->getBorders()->getRight()->getBorderStyle());
        $this->assertSame('0070C0', $jan->getStyle('E3')->getBorders()->getRight()->getColor()->getRGB());

        // Cancelled number stays, with the reason; manual number is included (no team, no SPPD).
        $this->assertSame('DIBATALKAN: Salah ketik perihal', $jan->getCell('D5')->getValue());
        $this->assertSame(['0030', '-', '30 Januari 2025', 'Uraian 30', '-'], $jan->rangeToArray('A7:E7')[0]);
        $this->assertSame('31 Januari 2025', $jan->getCell('E8')->getValue());
        $this->assertSame('FFE699', $jan->getStyle('E8')->getFill()->getStartColor()->getRGB());

        $feb = $buku->getSheetByName('FEBRUARI');
        $this->assertSame(['0012', '03 s.d 07 Februari 2025', 'F4B084'], [$feb->getCell('A3')->getValue(), $feb->getCell('E4')->getValue(), $feb->getStyle('E4')->getFill()->getStartColor()->getRGB()]);
        $this->assertSame(['0013', '=1+1', '10 Februari 2025'], [$feb->getCell('A5')->getValue(), $feb->getCell('B5')->getValue(), $feb->getCell('E6')->getValue()]);

        $des = $buku->getSheetByName('DESEMBER');
        $this->assertSame(['0099', '29 Desember 2025 s.d 02 Januari 2026', 'FF99FF'], [$des->getCell('A3')->getValue(), $des->getCell('E4')->getValue(), $des->getStyle('E4')->getFill()->getStartColor()->getRGB()]);

        foreach (['MARET', 'NOVEMBER'] as $kosong) {
            $this->assertSame(RekapTahunanSheet::KOSONG, $buku->getSheetByName($kosong)->getCell('A3')->getValue());
            $this->assertArrayHasKey('A3:E3', $buku->getSheetByName($kosong)->getMergeCells());
        }
    }

    /**
     * 1.2.1 Task 6: the legend follows urutan and holds the active klasifikasi plus the inactive ones the year's SPT use; with 6 of them
     * every legend column has the legend width and the print area reaches the last one.
     */
    public function test_legenda_enam_klasifikasi_urutan_nonaktif_dan_area_cetak(): void
    {
        $this->data(); // 2025 uses REGULER, KHUSUS, DIKLAT, LAIN_LAIN, LUAR_DAERAH
        $baru = fn (string $kode, array $o = []) => Klasifikasi::create($o + ['kode' => $kode, 'nama' => str_replace('_', ' ', $kode), 'syarat_selesai' => 'konfirmasi', 'urutan' => 6]);
        $baru('EVALUASI', ['warna' => '332288', 'urutan' => 7]);
        $baru('AUDIT_KINERJA', ['aktif' => false]); // inactive and unused: left out
        Klasifikasi::where('kode', 'DIKLAT')->update(['aktif' => false]); // inactive but used in 2025: kept
        Klasifikasi::where('kode', 'LAIN_LAIN')->update(['urutan' => 0]);

        // A year without SPT: only the active ones.
        $kosong = $this->buku(2031)->getSheetByName('MARET');
        $this->assertSame(['LAIN-LAIN', 'REGULER', 'KHUSUS', 'LUAR DAERAH', 'EVALUASI', null], $kosong->rangeToArray('G2:L2')[0]);
        $this->assertSame('A1:K3', $kosong->getPageSetup()->getPrintArea());

        $jan = $this->buku()->getSheetByName('JANUARI'); // last, so SAMPLE_REKAP_TAHUNAN keeps the 6-klasifikasi 2025 workbook
        $this->assertSame(['LAIN-LAIN', 'REGULER', 'KHUSUS', 'DIKLAT', 'LUAR DAERAH', 'EVALUASI', null], $jan->rangeToArray('G2:M2')[0]);
        foreach (['G', 'H', 'I', 'J', 'K', 'L'] as $kolom) {
            $this->assertEquals(14, $jan->getColumnDimension($kolom)->getWidth(), $kolom);
        }
        $this->assertSame('332288', $jan->getStyle('L2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $jan->getStyle('L2')->getFont()->getColor()->getRGB()); // dark colour: white text
        $this->assertSame('A1:L8', $jan->getPageSetup()->getPrintArea());
    }

    public function test_empty_year_still_has_twelve_sheets(): void
    {
        $buku = $this->buku(2031);

        $this->assertCount(12, $buku->getSheetNames());
        foreach ($buku->getAllSheets() as $s) {
            $this->assertSame(RekapTahunanSheet::KOSONG, $s->getCell('A3')->getValue());
        }
    }

    public function test_register_menu_uses_only_the_year_and_other_roles_are_refused(): void
    {
        $this->data();
        Excel::fake();

        Livewire::actingAs($this->bu)->test(Register::class)->set('tahun', 2025)->set('status', 'dibatalkan')->set('cari', 'tidak-ada')
            ->assertSee('Rekap Tahunan (Excel)')->assertSee('filter lain diabaikan')
            ->call('eksporRekapTahunan');
        Excel::assertDownloaded('rekap-tahunan-spt-2025.xlsx', function ($e) {
            $this->assertCount(12, $e->sheets());
            $this->assertCount(2 + 6, $e->sheets()[0]->array()); // all three January numbers despite the status and search filters

            return true;
        });

        foreach ([Role::Admin, Role::Pimpinan, Role::Irban] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->assertThrows(fn () => app(Ekspor::class)->minta($u, 'rekap-tahunan', 'xlsx', ['tahun' => 2025]), AuthorizationException::class);
        }
    }

    public function test_pdf_is_refused(): void
    {
        $this->assertThrows(fn () => app(Ekspor::class)->minta($this->bu, 'rekap-tahunan', 'pdf', ['tahun' => 2025]),
            fn (HttpException $e) => $e->getStatusCode() === 422);
    }

    public function test_admin_sets_validates_and_clears_the_colour(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $reguler = Klasifikasi::where('kode', 'REGULER')->first();
        $page = Livewire::actingAs($admin)->test(KlasifikasiPage::class)->call('edit', $reguler->id)
            ->assertSet('warna', 'BDD7EE')->assertSee('03 s.d 07 Februari 2025');

        foreach (['ZZZZZZ', 'ABCDE', 'ABCDEF1'] as $salah) {
            $page->set('warna', $salah)->call('save')->assertHasErrors(['warna' => 'regex']);
        }
        $this->assertSame('BDD7EE', $reguler->fresh()->warna);

        $page->set('warna', '#a1b2c3')->call('save')->assertHasNoErrors();
        $this->assertSame('A1B2C3', $reguler->fresh()->warna);
        $page->call('edit', $reguler->id)->set('warna', '')->call('save')->assertHasNoErrors();
        $this->assertNull($reguler->fresh()->warna);
    }
}
