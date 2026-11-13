<?php

namespace Tests\Feature\Rekap;

use App\Enums\Role;
use App\Exports\RekapSheet;
use App\Jobs\BuatEkspor;
use App\Models\Ekspor as EksporModel;
use App\Models\Irban;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunMonitoring;
use App\Services\Ekspor\PembangunRekap;
use App\Services\RealisasiItemPkpt;
use App\Services\RekapTriwulan;
use App\Services\Settings;
use App\Services\Triwulan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** Fase 9.2 Task 2: section choice (`bagian`), Rekap workbook (Gabungan + one sheet per irban) and its styling, read back with PhpSpreadsheet. */
class EksporRekapWorkbookTest extends TestCase
{
    use RefreshDatabase;
    use RekapFixture {
        setUp as private fixtureSetUp;
    }

    private User $pimpinan;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        app(Settings::class)->set('rekap.dasar_triwulan', 'tanggal_selesai');
        $this->pimpinan = User::factory()->create(['role' => Role::Pimpinan]);
    }

    private function buku(User $u, array $filter): Spreadsheet
    {
        $p = app(PembangunRekap::class);
        $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
        file_put_contents($path, Excel::raw($p->export($u, $p->filter($u, ['tahun' => 2026, ...$filter])), ExcelWriter::XLSX));
        $buku = IOFactory::load($path);
        @unlink($path);

        return $buku;
    }

    private function teks(Worksheet $s): array
    {
        return array_values(array_filter(array_merge(...$s->toArray(null, true, true, false)), fn ($c) => $c !== null && $c !== ''));
    }

    /** Row (1-based) whose column A is $a and column B is $b. */
    private function baris(Worksheet $s, string $a, ?string $b = null): array
    {
        foreach ($s->toArray(null, true, false, false) as $row) {
            if ($row[0] === $a && ($b === null || $row[1] === $b)) {
                return $row;
            }
        }
        $this->fail("Baris {$a} {$b} tidak ada");
    }

    public function test_bagian_terpilih_whitelists_orders_and_defaults(): void
    {
        $this->assertSame(['rr', 'jenis', 'detail'], PembangunRekap::bagianTerpilih(['detail', 'x', 'rr', 'jenis', 'rr', ['rr']]));
        $this->assertSame(['rr', 'klasifikasi', 'jenis'], PembangunRekap::bagianTerpilih([]));
        $this->assertSame(['rr', 'klasifikasi', 'jenis'], PembangunRekap::bagianTerpilih(['nope']));
        $this->assertSame(['rr', 'klasifikasi', 'jenis'], PembangunRekap::bagianTerpilih(null));
        $this->assertSame(['detail'], PembangunRekap::bagianTerpilih('detail'));
        $this->assertSame(['detail'], PembangunRekap::BAGIAN_DEFAULT['detail']);
    }

    public function test_file_name_suffix_only_when_not_all_sections(): void
    {
        $p = app(PembangunRekap::class);
        $nama = fn (array $f) => $p->namaFile($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026, ...$f]));
        $this->assertSame('rekap-triwulan-2026', $nama(['bagian' => ['detail', 'jenis', 'klasifikasi', 'rr']]));
        $this->assertSame('rekap-triwulan-2026-rr-detail', $nama(['bagian' => ['detail', 'rr']]));
        $this->assertSame('rekap-triwulan-2026-irban-'.$this->a->kode_nomor.'-klasifikasi', $nama(['irban' => $this->a->id, 'bagian' => ['klasifikasi']]));
    }

    public function test_sheets_for_the_three_user_scenarios(): void
    {
        $irbans = Irban::orderBy('id')->pluck('nama')->all();
        $this->assertSame(['Gabungan', ...$irbans], $this->buku($this->pimpinan, [])->getSheetNames());
        $this->assertSame(['Irban B'], $this->buku($this->pimpinan, ['irban' => $this->b->id])->getSheetNames());
        $irban = User::factory()->create(['role' => Role::Irban, 'irban_id' => $this->a->id]);
        $this->assertSame(['Irban A'], $this->buku($irban, ['irban' => $this->b->id])->getSheetNames());
    }

    public function test_only_the_selected_sections_and_gabungan_never_has_detail(): void
    {
        $buku = $this->buku($this->pimpinan, ['bagian' => ['detail', 'rr']]);
        $gabungan = $this->teks($buku->getSheetByName('Gabungan'));
        $irbanA = $this->teks($buku->getSheetByName('Irban A'));
        foreach (['Rencana vs Realisasi', 'Ringkasan per Irban'] as $t) {
            $this->assertContains($t, $gabungan);
        }
        $this->assertContains('Rencana vs Realisasi', $irbanA);
        $this->assertContains('Detail Realisasi per Item PKPT', $irbanA);
        foreach (['Per Klasifikasi', 'Per Jenis Penugasan'] as $t) {
            $this->assertNotContains($t, $gabungan);
            $this->assertNotContains($t, $irbanA);
        }
        $this->assertNotContains('Detail Realisasi per Item PKPT', $gabungan);
        $this->assertNotContains('Ringkasan per Irban', $irbanA);

        $this->assertSame(['Irban A', 'Irban B'], $this->buku($this->pimpinan, ['bagian' => ['detail']])->getSheetNames());
    }

    public function test_title_rows_and_header_style_widths_freeze_and_print_setup(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setTime(9, 15));
        $s = $this->buku($this->pimpinan, ['tw' => 1, 'bagian' => PembangunRekap::BAGIAN])->getSheetByName('Irban A');
        $this->assertSame(['Rekap Triwulanan 2026 · TW I', 'Irban A', 'Dibuat: 15 Desember 2026 09.15 WIB'], [$s->getCell('A1')->getValue(), $s->getCell('A2')->getValue(), $s->getCell('A3')->getValue()]);
        $this->assertNull($s->getCell('A4')->getValue());
        $this->assertSame('Rencana vs Realisasi', $s->getCell('A5')->getValue());
        $this->assertTrue($s->getStyle('A5')->getFont()->getBold());
        $this->assertEquals(12, $s->getStyle('A5')->getFont()->getSize());

        $kepala = $s->getStyle('A6');
        $this->assertSame('Irban', $s->getCell('A6')->getValue());
        $this->assertSame(RekapSheet::HEADER_ISI, $kepala->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $kepala->getFont()->getColor()->getRGB());
        $this->assertTrue($kepala->getFont()->getBold());
        $this->assertSame('center', $kepala->getAlignment()->getHorizontal());
        $this->assertSame(RekapSheet::GARIS, $s->getStyle('C7')->getBorders()->getLeft()->getColor()->getRGB());
        $this->assertSame('right', $s->getStyle('C7')->getAlignment()->getHorizontal());

        // label 45 | tanggal 26 > teks 22 | nomor 34 > angka 12 | tanggal 26 > angka | teks 22 > angka | teks 22 | panjang 60
        foreach (['A' => 45, 'B' => 26, 'C' => 34, 'D' => 26, 'E' => 22, 'F' => 22, 'G' => 60] as $k => $w) {
            $this->assertEquals($w, $s->getColumnDimension($k)->getWidth(), $k);
            $this->assertFalse($s->getColumnDimension($k)->getAutoSize(), $k);
        }

        $this->assertSame('A4', $s->getFreezePane());
        $ps = $s->getPageSetup();
        $this->assertSame([PageSetup::ORIENTATION_LANDSCAPE, PageSetup::PAPERSIZE_A4, 1, 0], [$ps->getOrientation(), $ps->getPaperSize(), $ps->getFitToWidth(), $ps->getFitToHeight()]);

        $this->assertSame('Rekap Triwulanan 2026', $this->buku($this->pimpinan, [])->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_total_row_is_bold_with_medium_top_border(): void
    {
        $s = $this->buku($this->pimpinan, ['bagian' => ['rr']])->getSheetByName('Irban A');
        $r = array_search('Total', array_column($s->toArray(null, true, true, false), 1), true) + 1;
        $this->assertTrue($s->getStyle("A{$r}")->getFont()->getBold());
        $this->assertSame('medium', $s->getStyle("E{$r}")->getBorders()->getTop()->getBorderStyle());
    }

    public function test_detail_jenis_and_periode_are_merged_per_item(): void
    {
        $s = $this->buku($this->pimpinan, ['bagian' => ['detail']])->getSheetByName('Irban A');
        $gabung = array_values(array_filter($s->getMergeCells(), fn ($m) => preg_match('/^A(\d+):A(\d+)$/', $m, $x) && $x[2] > $x[1]));
        $this->assertNotEmpty($gabung); // item a1 has two accepted SPT (s1, s2)
        [$atas, $bawah] = explode(':', $gabung[0]);
        $this->assertSame('Jenis Satu', $s->getCell($atas)->getValue());
        $this->assertContains('B'.substr($atas, 1).':B'.substr($bawah, 1), $s->getMergeCells());
        $this->assertSame('top', $s->getStyle($atas)->getAlignment()->getVertical());
    }

    public function test_numbers_on_each_sheet_equal_rekap_triwulan_for_that_irban(): void
    {
        $buku = $this->buku($this->pimpinan, []);
        foreach ([$this->a, $this->b] as $irban) {
            $s = $buku->getSheetByName($irban->nama);
            $data = app(RekapTriwulan::class)->hitung(2026, $irban->id);
            $sel = fn (array $c) => [$c['rencana'], $c['realisasi'], $c['persen'] ?? '–'];
            foreach (PembangunRekap::TW as $n => $tw) {
                $this->assertSame($sel($data['irban']['baris'][0]['tw'][$n]), array_slice($this->baris($s, $irban->nama, $tw), 2, 3), "{$irban->nama} {$tw}");
            }
            $this->assertSame($sel($data['irban']['baris'][0]['total']), array_slice($this->baris($s, $irban->nama, 'Total'), 2, 3));
            foreach ($data['jenis']['baris'] as $j) { // per jenis too, not only per irban: rencana | realisasi | % | jumlah SPT
                $this->assertSame([$j['total']['rencana'], $j['total']['realisasi'], $j['total']['persen'] ?? '–', $j['total']['jumlah']], array_slice($this->baris($s, $j['nama'], 'Setahun'), 2, 4), "{$irban->nama} {$j['nama']}");
            }
            foreach ($data['klasifikasi']['baris'] as $k) {
                $this->assertSame([$k['total']['jumlah'], $k['total']['berjalan'], $k['total']['selesai']], array_slice($this->baris($s, $k['nama'], 'Setahun'), 2, 3));
            }
        }
        $semua = app(RekapTriwulan::class)->hitung(2026);
        $this->assertSame([6, 3, 50], array_slice($this->baris($buku->getSheetByName('Gabungan'), 'Irban A'), 1, 3));
        $this->assertSame([$semua['irban']['total']['total']['rencana'], $semua['irban']['total']['total']['realisasi']], array_slice($this->baris($buku->getSheetByName('Gabungan'), 'Gabungan', 'Total'), 2, 2));
    }

    public function test_kendala_starting_with_equals_stays_text(): void
    {
        Spt::where('irban_id', $this->a->id)->whereDate('tanggal_mulai', '2026-02-01')->firstOrFail()
            ->kendala()->create(['kategori' => '=HYPERLINK("http://x")', 'dibuat_oleh' => $this->pimpinan->id, 'uraian' => 'uji']);
        $s = $this->buku($this->pimpinan, ['bagian' => ['detail']])->getSheetByName('Irban A');
        foreach ($s->getRowIterator() as $row) {
            $sel = $s->getCell('G'.$row->getRowIndex());
            if (str_starts_with((string) $sel->getValue(), '=HYPERLINK')) {
                $this->assertSame(DataType::TYPE_STRING, $sel->getDataType());

                return;
            }
        }
        $this->fail('Kendala tidak ada di sheet');
    }

    public function test_sheet_title_is_sanitized(): void
    {
        $this->assertSame('Irban AB', (new RekapSheet('x', 'Irban: A/B', 'y', []))->title());
        $this->assertSame('Irban ab', (new RekapSheet('x', 'Irban [a]*?\\b', 'y', []))->title());
        $this->assertSame(31, mb_strlen((new RekapSheet('x', str_repeat('é', 40), 'y', []))->title()));
        $this->b->update(['nama' => 'Irban: B/C']);
        $this->assertSame(['Irban BC'], $this->buku($this->pimpinan, ['irban' => $this->b->id])->getSheetNames());
    }

    public function test_jumlah_counts_only_selected_sections_times_sheets(): void
    {
        $p = app(PembangunRekap::class);
        $jumlah = fn (array $b, array $f = []) => $p->jumlah($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026, 'bagian' => $b, ...$f]));
        $sheets = Irban::count() + 1;
        $this->assertSame((2 + 5) * $sheets, $jumlah(['rr']));              // TW I–IV + Total
        $this->assertSame((2 + 1) * $sheets, $jumlah(['rr'], ['tw' => 2]));
        $kl = 2 + count(app(RekapTriwulan::class)->hitung(2026)['klasifikasi']['baris']) + 1;
        $this->assertSame($kl * $sheets, $jumlah(['klasifikasi']));
        $this->assertSame((2 + 5), $jumlah(['rr'], ['irban' => $this->a->id]));
        $this->assertGreaterThan(0, $jumlah(['detail']));
        $this->assertSame($jumlah(['rr']) + $jumlah(['detail']), $jumlah(['rr', 'detail']));
    }

    public function test_queued_export_stores_bagian_and_the_job_honours_it(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->app->bind(PembangunRekap::class, fn () => new class(app(RekapTriwulan::class), app(RealisasiItemPkpt::class)) extends PembangunRekap
        {
            public function jumlah(User $u, array $f): int
            {
                return Ekspor::BATAS_LANGSUNG + 1;
            }
        });

        $this->assertNull(app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'xlsx', ['tahun' => 2026, 'bagian' => ['detail', 'bogus', 'rr']]));
        $e = EksporModel::sole();
        $this->assertSame(['rr', 'detail'], $e->filter['bagian']);
        $this->assertSame('rekap-triwulan-2026-rr-detail.xlsx', $e->nama_file);
        Queue::assertPushed(BuatEkspor::class);

        Notification::fake();
        app()->call([new BuatEkspor($e), 'handle']);
        $buku = IOFactory::load(Storage::disk('local')->path($e->fresh()->path));
        $this->assertSame(['Gabungan', 'Irban A', 'Irban B'], $buku->getSheetNames());
        $teks = $this->teks($buku->getSheetByName('Irban A'));
        $this->assertContains('Detail Realisasi per Item PKPT', $teks);
        $this->assertNotContains('Per Klasifikasi', $teks);
    }

    public function test_pdf_html_contains_only_selected_sections(): void
    {
        $p = app(PembangunRekap::class);
        $html = fn (array $b) => $p->html($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026, 'bagian' => $b]));

        $rrDetail = $html(['rr', 'detail']);
        $this->assertStringContainsString('Rencana vs Realisasi', $rrDetail);
        $this->assertStringContainsString('Detail Realisasi per Item PKPT', $rrDetail);
        $this->assertStringNotContainsString('Per Klasifikasi', $rrDetail);
        $this->assertStringNotContainsString('Per Jenis Penugasan', $rrDetail);

        $jenis = $html(['jenis']);
        $this->assertStringContainsString('Per Jenis Penugasan', $jenis);
        $this->assertStringContainsString('class="blok"', $jenis);
        foreach (['Rencana vs Realisasi', 'Per Klasifikasi', 'Detail Realisasi per Item PKPT'] as $t) {
            $this->assertStringNotContainsString($t, $jenis);
        }
    }

    /** A detail block taller than a page (page-break-inside: avoid) still breaks over pages instead of being dropped. */
    public function test_pdf_long_detail_block_spans_pages(): void
    {
        $html = view('pdf.rekap-triwulan', ['tahun' => 2026, 'tw' => 'Semua TW', 'irban' => null, 'pkptDisahkan' => true, 'detailJudul' => 'Detail', 'dibuat' => 'x',
            'detailKolom' => ['A', 'B'], 'pilihan' => ['detail'], 'bagian' => [],
            'detail' => [['irban' => 'Irban A', 'baris' => array_map(fn ($i) => ["Baris {$i}", 'x'], range(1, 150)), 'gabung' => []]]])->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();

        $this->assertGreaterThan(2, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    /** Sheet names must stay unique after sanitizing and cutting to 31 characters, also against "Gabungan" and case-insensitively. */
    public function test_sheet_titles_are_made_unique(): void
    {
        $this->a->update(['nama' => 'Gabungan']);
        $this->b->update(['nama' => 'Irban: B']);
        Irban::factory()->create(['nama' => 'Irban B']);
        Irban::factory()->create(['nama' => 'IRBAN/ b']); // differs in the database (case-insensitive unique on MySQL), same title once sanitized
        Irban::factory()->create(['nama' => str_repeat('é', 31).'1']);
        Irban::factory()->create(['nama' => str_repeat('é', 31).'2']);

        $nama = $this->buku($this->pimpinan, [])->getSheetNames();

        $this->assertSame(['Gabungan', 'Gabungan (2)', 'Irban B', 'Irban B (2)', 'IRBAN b (3)', str_repeat('é', 31), str_repeat('é', 27).' (2)'], $nama);
        $this->assertSame(count($nama), count(array_unique(array_map('mb_strtolower', $nama))));
    }

    /** jumlah() and export() of one request share one hitung() per scope (tahun, irban). */
    public function test_jumlah_and_export_calculate_each_scope_once(): void
    {
        $panggilan = new \ArrayObject;
        $this->app->bind(RekapTriwulan::class, fn () => new class($panggilan, app(Triwulan::class)) extends RekapTriwulan
        {
            public function __construct(private \ArrayObject $panggilan, Triwulan $tw)
            {
                parent::__construct($tw);
            }

            public function hitung(int $tahun, ?int $irbanId = null, array $saring = []): array
            {
                $this->panggilan[] = "{$tahun}|{$irbanId}";

                return parent::hitung($tahun, $irbanId, $saring);
            }
        });
        $p = app(PembangunRekap::class);
        $f = $p->filter($this->pimpinan, ['tahun' => 2026]);

        $p->jumlah($this->pimpinan, $f);
        $p->export($this->pimpinan, $f);

        // Gabungan + one per irban; the Gabungan scope is shared with jumlah().
        $this->assertEqualsCanonicalizing(['2026|', "2026|{$this->a->id}", "2026|{$this->b->id}"], $panggilan->getArrayCopy());

        $panggilan->exchangeArray([]);
        $f = $p->filter($this->pimpinan, ['tahun' => 2026, 'irban' => $this->a->id]);
        $baru = app(PembangunRekap::class);
        $baru->jumlah($this->pimpinan, $f);
        $baru->export($this->pimpinan, $f);
        $this->assertSame(["2026|{$this->a->id}"], $panggilan->getArrayCopy());
    }

    public function test_print_titles_repeat_the_title_rows(): void
    {
        $s = $this->buku($this->pimpinan, [])->getSheetByName('Irban A');

        $this->assertSame([1, 3], $s->getPageSetup()->getRowsToRepeatAtTop());
    }

    public function test_detail_status_and_kesesuaian_use_the_same_labels_as_monitoring(): void
    {
        $s = $this->buku($this->pimpinan, ['bagian' => ['detail']])->getSheetByName('Irban A');
        $sel = array_merge(...array_map(fn ($r) => [$r[4] ?? null, $r[5] ?? null], $s->toArray(null, true, true, false)));

        $this->assertContains(PembangunMonitoring::KESESUAIAN['sesuai'], $sel);
        $this->assertContains('Selesai', $sel);
        $semua = array_merge(...array_map(fn ($sh) => array_merge(...$sh->toArray(null, true, true, false)), $this->buku($this->pimpinan, ['bagian' => ['detail']])->getAllSheets()));
        $this->assertTrue(count(array_filter($semua, fn ($c) => is_string($c) && str_ends_with($c, ', Terlambat'))) > 0, 'A late SPT row shows ", Terlambat" after its status.');
    }

    /** 1.3 Task 2: hidden rencana cells are the text "–" (not 0, not empty) and the C1 note is a Catatan section under the table. */
    public function test_saring_sel_tanda_sebagai_teks_dan_catatan_di_bawah_tabel(): void
    {
        $polos = $this->buku($this->pimpinan, []);
        foreach ($polos->getAllSheets() as $s) {
            $this->assertNotContains(RekapTriwulan::CATATAN_TANPA_RENCANA, $this->teks($s));
        }

        $buku = $this->buku($this->pimpinan, ['klasifikasi' => $this->k]);
        $s = $buku->getSheetByName('Gabungan');
        $this->assertSame('Rekap Triwulanan 2026 · Klasifikasi: KHUSUS', $s->getCell('A1')->getValue());
        $this->assertSame(['Gabungan', 'Total', '–', 0, '–'], array_slice($this->baris($s, 'Gabungan', 'Total'), 0, 5));
        foreach ($s->getRowIterator() as $row) {
            if ($s->getCell('A'.$row->getRowIndex())->getValue() === 'Gabungan' && $s->getCell('B'.$row->getRowIndex())->getValue() === 'Total') {
                $this->assertSame(DataType::TYPE_STRING, $s->getCell('C'.$row->getRowIndex())->getDataType());
            }
        }
        $teks = $this->teks($s);
        // Rencana vs Realisasi (+ Ringkasan per Irban) and Per Jenis each end with a Catatan carrying C1.
        $this->assertSame(2, count(array_keys($teks, RekapTriwulan::CATATAN_TANPA_RENCANA, true)));
        $this->assertLessThan(array_search('Per Klasifikasi', $teks, true), array_search(RekapTriwulan::CATATAN_TANPA_RENCANA, $teks, true));
        $this->assertSame(['Irban A', '–', 0, '–'], array_slice($this->barisRingkasan($s, 'Irban A'), 0, 4));
        $this->assertContains(RekapTriwulan::CATATAN_TANPA_RENCANA, $this->teks($buku->getSheetByName('Irban A')));
        $this->assertNotContains(PembangunRekap::CATATAN_TARGET, $teks); // the target note is about rencana, which is hidden
    }

    /** Row of "Ringkasan per Irban" (columns Irban, Rencana, Realisasi, %) for $nama. */
    private function barisRingkasan(Worksheet $s, string $nama): array
    {
        $rows = $s->toArray(null, true, false, false);
        $mulai = array_search('Ringkasan per Irban', array_column($rows, 0), true);
        foreach (array_slice($rows, $mulai) as $row) {
            if ($row[0] === $nama) {
                return $row;
            }
        }
        $this->fail("Ringkasan {$nama} tidak ada");
    }

    public function test_nama_file_memuat_saring(): void
    {
        $p = app(PembangunRekap::class);
        $kode = KodeArsip::where('kode', '700')->value('id');
        $nama = fn (array $f) => $p->namaFile($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026, ...$f]));
        $this->assertSame('rekap-triwulan-2026-rr-klasifikasi-jenis', $nama([]));
        $this->assertSame('rekap-triwulan-2026-klasifikasi-reguler-kode-arsip-700-pengawasan-umum-jenis-lainnya-rr', $nama(['klasifikasi' => $this->r, 'kode_arsip' => $kode, 'jenis' => 'lainnya', 'bagian' => ['rr']]));
        $this->assertSame('rekap-triwulan-2026-jenis-jenis-satu', $nama(['jenis' => (string) $this->j1->id, 'klasifikasi' => '99999', 'bagian' => PembangunRekap::BAGIAN]));
    }

    /** Review Focus 2: the queued job rebuilds the filter through filter(), so the stored `saring` is applied and re-validated. */
    public function test_ekspor_antre_membawa_dan_memvalidasi_ulang_saring(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->app->bind(PembangunRekap::class, fn () => new class(app(RekapTriwulan::class), app(RealisasiItemPkpt::class)) extends PembangunRekap
        {
            public function jumlah(User $u, array $f): int
            {
                return Ekspor::BATAS_LANGSUNG + 1;
            }
        });

        $this->assertNull(app(Ekspor::class)->minta($this->pimpinan, 'rekap', 'xlsx', ['tahun' => 2026, 'klasifikasi' => (string) $this->k, 'kode_arsip' => '99999', 'bagian' => ['rr']]));
        $e = EksporModel::sole();
        $this->assertSame(['klasifikasi' => $this->k, 'kode_arsip' => null, 'jenis' => null], $e->filter['saring']);
        $this->assertSame('rekap-triwulan-2026-klasifikasi-khusus-rr.xlsx', $e->nama_file);

        Notification::fake();
        app()->call([new BuatEkspor($e), 'handle']);
        $s = IOFactory::load(Storage::disk('local')->path($e->fresh()->path))->getSheetByName('Gabungan');
        $this->assertSame(['Gabungan', 'Total', '–', 0, '–'], $this->baris($s, 'Gabungan', 'Total'));
        $this->assertContains(RekapTriwulan::CATATAN_TANPA_RENCANA, $this->teks($s));

        // A stored id that no longer exists is ignored when the job re-validates (no filter, no note, no failure).
        $e2 = EksporModel::create(['user_id' => $this->pimpinan->id, 'jenis' => 'rekap', 'format' => 'xlsx', 'nama_file' => 'x.xlsx', 'status' => 'menunggu',
            'filter' => [...$e->filter, 'saring' => ['klasifikasi' => 99999, 'kode_arsip' => null, 'jenis' => null]]]);
        app()->call([new BuatEkspor($e2), 'handle']);
        $s = IOFactory::load(Storage::disk('local')->path($e2->fresh()->path))->getSheetByName('Gabungan');
        $this->assertSame(['Gabungan', 'Total', 9, 4, 44], $this->baris($s, 'Gabungan', 'Total'));
        $this->assertSame('selesai', $e2->fresh()->status);
    }

    public function test_pdf_saring_ringkasan_dan_catatan(): void
    {
        $p = app(PembangunRekap::class);
        $html = $p->html($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026, 'kode_arsip' => KodeArsip::where('kode', '700')->value('id'), 'jenis' => (string) $this->j1->id]));
        $this->assertStringContainsString('Kode Arsip: 700 · Pengawasan Umum', $html);
        $this->assertStringContainsString('Jenis: Jenis Satu', $html);
        $this->assertSame(2, substr_count($html, RekapTriwulan::CATATAN_TANPA_RENCANA));
        $this->assertStringNotContainsString(PembangunRekap::CATATAN_TARGET, $html);
        $this->assertStringStartsWith('%PDF', Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output());

        $polos = $p->html($this->pimpinan, $p->filter($this->pimpinan, ['tahun' => 2026]));
        $this->assertStringNotContainsString(RekapTriwulan::CATATAN_TANPA_RENCANA, $polos);
        $this->assertStringNotContainsString('Klasifikasi: ', $polos);
    }
}
