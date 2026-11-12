<?php

namespace Tests\Feature\Impor;

use App\Livewire\Admin\Impor as Page;
use App\Models\Aktivitas;
use App\Models\Spt;
use App\Models\User;
use App\Services\Import\SptImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class HalamanImporTest extends TestCase
{
    use RefreshDatabase, SptImportTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedImpor();
    }

    private function unggah(array $rows)
    {
        return Livewire::actingAs($this->admin)->test(Page::class)
            ->set('file', UploadedFile::fake()->createWithContent('lama.xlsx', file_get_contents($this->xlsx($rows))));
    }

    /** 2 valid, 1 warning (no PKPT item for 2024), 1 error. */
    private function campuran(): array
    {
        return [
            $this->baris(['nomor_spt' => '700/1/I/SPT/2025']),
            $this->baris(['nomor_spt' => '700/2/I/SPT/2025']),
            $this->baris(['nomor_spt' => '700/3/I/SPT/2024', 'tanggal_spt' => '28/02/2024', 'tanggal_mulai' => '03/03/2024', 'tanggal_selesai' => '14/03/2024', 'nomor_lhp' => '', 'tanggal_lhp' => '', 'selesai' => 'tidak']),
            $this->baris(['nomor_spt' => '700/4/I/SPT/2025', 'irban' => 'Irban IX']),
        ];
    }

    public function test_akses(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Impor Data Lama')->assertSee('Petunjuk kolom');
        foreach (['pimpinan', 'irban', 'bagianUmum'] as $state) {
            $u = User::factory()->{$state}()->create();
            $this->actingAs($u)->get('/admin/impor')->assertForbidden();
            Livewire::actingAs($u)->test(Page::class)->assertForbidden();
        }
        $c = Livewire::actingAs($this->admin)->test(Page::class);
        $this->actingAs(User::factory()->pimpinan()->create()); // actions re-check the gate for the current user
        $c->call('unduhTemplat')->assertForbidden();
        $c = Livewire::actingAs($this->admin)->test(Page::class);
        $this->actingAs(User::factory()->pimpinan()->create()); // render re-checks the gate too
        $c->set('galatSaja', true)->assertForbidden();
    }

    public function test_unduh_templat(): void
    {
        $c = Livewire::actingAs($this->admin)->test(Page::class)->call('unduhTemplat')->assertFileDownloaded('templat-impor-spt.xlsx');
        $path = $this->berkasXlsxSementara('t');
        file_put_contents($path, $c->effects['download']['content'] ? base64_decode($c->effects['download']['content']) : '');
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame(SptImportParser::HEADERS, array_values(array_filter($sheet->rangeToArray('A1:R1')[0])));
        foreach (['A', 'K', 'L', 'M', 'N'] as $col) {
            $this->assertSame('@', $sheet->getColumnStyle($col)->getNumberFormat()->getFormatCode(), $col);
        }
        $this->assertSame('General', $sheet->getColumnStyle('B')->getNumberFormat()->getFormatCode()); // widths now give every column a style, but not the text format
    }

    public function test_alur_unggah_pratinjau_impor(): void
    {
        $c = $this->unggah($this->campuran())->assertSet('step', 2)
            ->assertSee('2 valid')->assertSee('1 peringatan')->assertSee('1 galat')->assertSee('Impor 3 baris');
        $c->call('impor')->assertSet('step', 2);
        $this->assertSame(0, Spt::count());

        $c->set('lewati', true)->call('impor')->assertSet('step', 3)->assertSet('diimpor', 3)
            ->assertDispatched('toast', type: 'success', message: '3 SPT berhasil diimpor.')->assertSee('3 SPT berhasil diimpor.');
        $this->assertSame(3, Spt::count());
        $this->assertSame(3, Aktivitas::imporTerakhir()->properties['jumlah']);
        $this->assertNull($c->get('file'));
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'), 'the Livewire temp upload and its .json sidecar are deleted');
        $c->call('ulangi')->assertSet('step', 1)->assertSet('raw', []);
    }

    public function test_unduh_galat(): void
    {
        $c = $this->unggah($this->campuran())->call('unduhGalat')->assertFileDownloaded('galat-impor-spt.xlsx');
        $path = $this->berkasXlsxSementara('g');
        file_put_contents($path, base64_decode($c->effects['download']['content']));
        $this->assertSame([['Baris', 'Kolom', 'Pesan'], ['5', 'irban', 'Irban "Irban IX" tidak ditemukan.']],
            array_map(fn ($r) => array_map('strval', $r), IOFactory::load($path)->getActiveSheet()->toArray()));
    }

    public function test_raw_dirusak_lebih_dari_maks(): void
    {
        $c = $this->unggah($this->campuran())->assertSet('step', 2);
        $c->set('raw', array_fill(0, SptImportParser::MAX_ROWS + 1, ['baris' => 2]))
            ->assertSet('step', 1)->assertSee(SptImportParser::capMessage());
    }

    public function test_toast_balapan(): void
    {
        $c = $this->unggah([$this->baris(['nomor_spt' => '700/9/I/SPT/2025'])])->assertSet('step', 2);
        // Real race: another request takes the number after the server-side recompute but before our insert (the `creating` hook runs inside the import transaction).
        $diambil = false;
        $dispatcher = Spt::getEventDispatcher();
        $event = 'eloquent.creating: '.Spt::class;
        $sebelumnya = $dispatcher->getListeners($event);
        Spt::creating(function () use (&$diambil) {
            if (! $diambil) {
                $diambil = true;
                Spt::withoutEvents(fn () => Spt::factory()->diterima()->create(['nomor_lengkap' => '700/9/I/SPT/2025']));
            }
        });
        try {
            $c->call('impor')->assertSet('step', 2)->assertDispatched('toast', type: 'error', message: 'Nomor SPT 700/9/I/SPT/2025 sudah dipakai. Muat ulang pratinjau.');
        } finally {
            // Drop only the listener this test added: forget() the event, then put back what was there before.
            $dispatcher->forget($event);
            foreach ($sebelumnya as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
        $this->assertTrue($diambil);
        $this->assertSame(0, Spt::count()); // the whole import rolled back (the racing row shares our connection in this test)
    }

    public function test_berkas_hanya_judul_ditolak(): void
    {
        $this->unggah([])->assertSet('step', 1)->assertHasErrors('file');
    }

    public function test_semua_galat_dengan_lewati_ditolak_server(): void
    {
        $c = $this->unggah([$this->baris(['irban' => 'Irban IX']), $this->baris(['nomor_spt' => '700/2/I/SPT/2025', 'irban' => 'Irban IX'])])
            ->assertSet('step', 2)->assertSee('2 galat')->set('lewati', true);
        $c->call('impor')->assertSet('step', 2)->assertDispatched('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');
        $this->assertSame(0, Spt::count());
        $this->assertNull(Aktivitas::imporTerakhir());
    }

    public function test_kartu_impor_terakhir(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertSee('Impor terakhir')->assertSee('Belum ada');
        Aktivitas::catatImpor($this->admin, 'spt', 'a.xlsx', 12);
        $this->actingAs($this->admin)->get('/admin')->assertSee('12 baris')->assertSee('SPT data lama');
    }
}
