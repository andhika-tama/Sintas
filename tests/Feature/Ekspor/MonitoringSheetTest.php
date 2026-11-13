<?php

namespace Tests\Feature\Ekspor;

use App\Enums\Role;
use App\Exports\RekapSheet;
use App\Models\Ekspor as EksporModel;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunMonitoring;
use App\Services\MonitoringSpt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** 1.2.1 Task 2: the styled Monitoring workbook (revokes 1.1.4 K4 for Monitoring). */
class MonitoringSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $pimpinan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00', 'Asia/Jakarta'));
        $this->pimpinan = User::factory()->create(['role' => Role::Pimpinan]);
    }

    private function baca(array $query = []): Worksheet
    {
        $p = new PembangunMonitoring(app(MonitoringSpt::class));
        $base = tempnam(sys_get_temp_dir(), 'm');
        $tmp = $base.'.xlsx';
        file_put_contents($tmp, Excel::raw($p->export($this->pimpinan, $p->filter($this->pimpinan, $query)), \Maatwebsite\Excel\Excel::XLSX));
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        unlink($base); // tempnam leaves its own empty file

        return $sheet;
    }

    public function test_content_styles_and_print_setup(): void
    {
        $irban = Irban::factory()->create(['nama' => 'Irban A']);
        $jenis = JenisPenugasan::factory()->create(['nama' => 'Jenis Satu']);
        $spt = Spt::factory()->selesai()->create(['irban_id' => $irban->id, 'jenis_penugasan_id' => $jenis->id, 'tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-20', 'nomor_lengkap' => '1e5']);
        $spt->forceFill(['tahun' => 2026])->save();
        $s = $this->baca();

        $this->assertSame('Monitoring SPT 2026', $s->getCell('A1')->getValue());
        $this->assertStringStartsWith('Filter:', $s->getCell('A2')->getValue());
        $this->assertStringContainsString('1 SPT', $s->getCell('A3')->getValue());
        foreach (PembangunMonitoring::KOLOM as $i => $k) {
            $this->assertSame($k, $s->getCell([$i + 1, 4])->getValue());
        }
        $this->assertSame(DataType::TYPE_STRING, $s->getCell('A5')->getDataType());
        $this->assertSame('1e5', $s->getCell('A5')->getValue());

        $z = chr(64 + count(PembangunMonitoring::KOLOM));
        $h = $s->getStyle("A4:{$z}4");
        $this->assertSame('FF'.RekapSheet::HEADER_ISI, $h->getFill()->getStartColor()->getARGB());
        $this->assertTrue($h->getFont()->getBold());
        $this->assertSame('FFFFFFFF', $h->getFont()->getColor()->getARGB());
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $h->getAlignment()->getHorizontal());

        $tipe = ['nomor', 'teks', 'teks', 'panjang', 'tanggal', 'tanggal', 'teks', 'teks', 'rupiah', 'panjang'];
        foreach ($tipe as $i => $t) {
            $this->assertEquals(RekapSheet::LEBAR[$t], $s->getColumnDimensionByColumn($i + 1)->getWidth(), 'kolom '.($i + 1));
        }
        $this->assertSame('B5', $s->getFreezePane());
        $this->assertSame("A4:{$z}5", $s->getAutoFilter()->getRange());
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $s->getPageSetup()->getOrientation());
        $this->assertSame(1, $s->getPageSetup()->getFitToWidth());
        $this->assertSame([4, 4], $s->getPageSetup()->getRowsToRepeatAtTop());
    }

    /** 1.3 Task 4 (Review Focus 5): Realisasi Dana is a number with the Rupiah format; null is an empty cell, 0 is a real 0. */
    public function test_realisasi_dana_is_numeric_with_rupiah_format_and_null_stays_empty(): void
    {
        foreach ([['A-DANA', 600000, '2026-03-05'], ['B-KOSONG', null, '2026-03-06'], ['C-NOL', 0, '2026-03-07']] as [$nomor, $dana, $mulai]) {
            $spt = Spt::factory()->selesai()->create(['tanggal_mulai' => $mulai, 'tanggal_selesai' => '2026-03-20', 'nomor_lengkap' => $nomor]);
            $spt->forceFill(['tahun' => 2026, 'realisasi_dana' => $dana])->save();
        }
        $s = $this->baca();

        $this->assertSame('Status', $s->getCell('H4')->getValue());
        $this->assertSame('Realisasi Dana', $s->getCell('I4')->getValue());
        $this->assertSame('Permasalahan/Kendala', $s->getCell('J4')->getValue());
        $this->assertSame(['A-DANA', 'B-KOSONG', 'C-NOL'], [$s->getCell('A5')->getValue(), $s->getCell('A6')->getValue(), $s->getCell('A7')->getValue()]);

        $isi = $s->getCell('I5');
        $this->assertSame(DataType::TYPE_NUMERIC, $isi->getDataType());
        $this->assertEquals(600000, $isi->getValue());
        $this->assertSame('"Rp"#,##0', $s->getStyle('I5')->getNumberFormat()->getFormatCode());
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $s->getStyle('I5')->getAlignment()->getHorizontal());

        $this->assertNull($s->getCell('I6')->getValue()); // not filled: empty, not 0
        $nol = $s->getCell('I7');
        $this->assertSame(DataType::TYPE_NUMERIC, $nol->getDataType());
        $this->assertSame(0, (int) $nol->getValue());
        $this->assertNotNull($nol->getValue());
    }

    /** The same through the real download path: a queued Ekspor row is written to disk by Ekspor::tulis and read back from the file. */
    public function test_realisasi_dana_survives_the_queued_export_file(): void
    {
        Storage::fake('local');
        $spt = Spt::factory()->selesai()->create(['tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-20']);
        $spt->forceFill(['tahun' => 2026, 'realisasi_dana' => 1250000500])->save();
        $e = EksporModel::create(['user_id' => $this->pimpinan->id, 'jenis' => 'monitoring', 'format' => 'xlsx', 'filter' => ['tahun' => 2026], 'nama_file' => 'm.xlsx', 'status' => 'menunggu']);
        app(Ekspor::class)->tulis($e);
        $s = IOFactory::load(Storage::disk('local')->path($e->fresh()->path))->getActiveSheet();

        $this->assertSame(DataType::TYPE_NUMERIC, $s->getCell('I5')->getDataType());
        $this->assertEquals(1250000500, $s->getCell('I5')->getValue());
        $this->assertSame('"Rp"#,##0', $s->getStyle('I5')->getNumberFormat()->getFormatCode());
    }

    /** The PDF puts Tim · PPJ under the Irban and shows Realisasi Dana as text (Rp600.000, Rp0, –). */
    public function test_realisasi_dana_in_the_pdf(): void
    {
        foreach ([['A-DANA', 600000, '2026-03-05'], ['B-KOSONG', null, '2026-03-06'], ['C-NOL', 0, '2026-03-07']] as [$nomor, $dana, $mulai]) {
            $spt = Spt::factory()->selesai()->create(['tanggal_mulai' => $mulai, 'tanggal_selesai' => '2026-03-20', 'nomor_lengkap' => $nomor]);
            $spt->forceFill(['tahun' => 2026, 'realisasi_dana' => $dana])->save();
        }
        $p = new PembangunMonitoring(app(MonitoringSpt::class));
        $html = $p->html($this->pimpinan, $p->filter($this->pimpinan, []));

        $this->assertStringContainsString('<th>Realisasi Dana</th>', $html);
        $this->assertStringNotContainsString('Tim · PPJ', $html);
        $this->assertSame(9, substr_count(explode('</tr>', $html)[0], '<th>'));
        $this->assertMatchesRegularExpression('#<td>Selesai</td><td>Rp600\.000</td><td>–</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>Selesai</td><td>–</td><td>–</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>Selesai</td><td>Rp0</td><td>–</td>#u', $html);
    }

    public function test_formula_like_text_is_stored_as_string(): void
    {
        $spt = Spt::factory()->selesai()->create(['tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-20', 'nomor_lengkap' => '=1+1']);
        $spt->forceFill(['tahun' => 2026])->save();
        $c = $this->baca()->getCell('A5');

        $this->assertSame(DataType::TYPE_STRING, $c->getDataType());
        $this->assertSame('=1+1', $c->getValue());
    }

    public function test_kesesuaian_filter_is_named_in_the_summary(): void
    {
        $s = $this->baca(['kesesuaian' => 'sesuai']);

        $this->assertStringContainsString('Kesesuaian: Sesuai', $s->getCell('A2')->getValue());
        $this->assertSame('Nomor SPT', $s->getCell('A4')->getValue()); // empty result still has the header
    }

    /** 1.3 Task 1: the three new filters narrow the rows and are named in the Excel summary, the PDF header and the queued export. */
    public function test_klasifikasi_kode_arsip_and_jenis_filters_reach_every_export(): void
    {
        Storage::fake('local');
        $reguler = Klasifikasi::where('kode', 'REGULER')->firstOrFail();
        $k800 = KodeArsip::firstOrCreate(['kode' => '800'], ['nama' => 'Kepegawaian']);
        $a = Spt::factory()->diterima()->create(['klasifikasi_id' => $reguler->id, 'kode_arsip_id' => $k800->id, 'jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => 'Evaluasi khusus', 'tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-20']);
        $b = Spt::factory()->diterima()->create(['tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-20']);
        $f = ['tahun' => 2026, 'klasifikasi' => (string) $reguler->id, 'kode_arsip' => (string) $k800->id, 'jenis' => 'lainnya'];
        $p = new PembangunMonitoring(app(MonitoringSpt::class));
        $ringkasan = ['Klasifikasi: '.$reguler->nama, 'Kode Arsip: 800 · Kepegawaian', 'Jenis: Lainnya'];

        $teks = $this->baca($f)->getCell('A2')->getValue();
        foreach ($ringkasan as $baris) {
            $this->assertStringContainsString($baris, $teks);
        }
        $html = $p->html($this->pimpinan, $p->filter($this->pimpinan, $f));
        $this->assertStringContainsString(implode('; ', $ringkasan), $html);
        $this->assertStringContainsString($a->nomor_lengkap, $html);
        $this->assertStringNotContainsString($b->nomor_lengkap, $html);
        $this->assertSame(1, $p->jumlah($this->pimpinan, $p->filter($this->pimpinan, $f)));

        // Queued export: the stored filter is rebuilt through filter(), so the new keys must survive and be re-validated (a bogus id is dropped).
        foreach ([[$f, 1], [['tahun' => 2026, 'klasifikasi' => '999999', 'kode_arsip' => 'x', 'jenis' => 'bukan'], 2]] as [$simpan, $baris]) {
            $e = EksporModel::create(['user_id' => $this->pimpinan->id, 'jenis' => 'monitoring', 'format' => 'xlsx', 'filter' => $simpan, 'nama_file' => 'm.xlsx', 'status' => 'menunggu']);
            app(Ekspor::class)->tulis($e);
            $sheet = IOFactory::load(Storage::disk('local')->path($e->fresh()->path))->getActiveSheet();
            $this->assertSame($baris === 1, str_contains((string) $sheet->getCell('A2')->getValue(), 'Klasifikasi: '));
            $this->assertSame($baris, count(array_filter($sheet->toArray(null, true, false), fn ($r) => in_array($a->nomor_lengkap, $r, true) || in_array($b->nomor_lengkap, $r, true))));
        }
    }
}
