<?php

namespace Tests\Feature\Ekspor;

use App\Enums\Role;
use App\Exports\RekapSheet;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor\PembangunMonitoring;
use App\Services\MonitoringSpt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $tipe = ['nomor', 'teks', 'panjang', 'tanggal', 'tanggal', 'teks', 'teks', 'panjang'];
        foreach ($tipe as $i => $t) {
            $this->assertEquals(RekapSheet::LEBAR[$t], $s->getColumnDimensionByColumn($i + 1)->getWidth(), 'kolom '.($i + 1));
        }
        $this->assertSame('B5', $s->getFreezePane());
        $this->assertSame("A4:{$z}5", $s->getAutoFilter()->getRange());
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $s->getPageSetup()->getOrientation());
        $this->assertSame(1, $s->getPageSetup()->getFitToWidth());
        $this->assertSame([4, 4], $s->getPageSetup()->getRowsToRepeatAtTop());
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
}
