<?php

namespace Tests\Feature\Demo;

use App\Actions\Spt\ValidatePersonel;
use App\Enums\StatusPengajuan;
use App\Enums\StatusPerubahan;
use App\Enums\StatusPkpt;
use App\Enums\StatusSpt;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\IrbanWilayah;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\Komentar;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptPerubahan;
use App\Models\Spt;
use App\Models\SptDokumen;
use App\Models\Tim;
use App\Models\User;
use App\Services\ColorRules;
use App\Services\Ekspor\PembangunRekap;
use App\Services\PenandaSpt;
use App\Services\Publik\RealisasiPublik;
use App\Services\RekapTriwulan;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KinerjaSeeder;
use Database\Seeders\PkptIrbanIDemo2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** E11: `migrate:fresh --seed` (non-production) gives every role and every irban a full presentation; production gets reference rows only. */
class DataDemoTest extends TestCase
{
    use RefreshDatabase;

    private const IRBAN = ['irban1' => 'Irban I', 'irban2' => 'Irban II', 'irban3' => 'Irban III', 'irban4' => 'Irban IV', 'irsus' => 'Irban Khusus'];

    private function seedDemo(string $tanggal = '2026-10-15'): void
    {
        $this->travelTo(CarbonImmutable::parse($tanggal.' 10:00:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);
    }

    /** @return list<string> status labels of this irban's 2026 SPT, a late Penyusunan Laporan counted as `terlambat` */
    private function label2026(int $irbanId): array
    {
        $penanda = app(PenandaSpt::class);

        return Spt::where('irban_id', $irbanId)->tahunPenugasan(2026)->get()
            ->map(fn (Spt $s) => in_array('terlambat', $penanda->untuk($s), true) ? 'terlambat' : $s->status()->value)->all();
    }

    public function test_akun_pkpt_dan_perubahan(): void
    {
        $this->seedDemo();

        foreach (['admin', 'pimpinan', 'bagianumum', ...array_keys(self::IRBAN)] as $username) {
            $u = User::where('username', $username)->first();
            $this->assertNotNull($u, $username);
            $this->assertTrue(Hash::check('password', $u->password), $username);
            $this->assertTrue($u->unreadNotifications()->exists(), "notifikasi belum dibaca: {$username}");
        }
        foreach (self::IRBAN as $username => $nama) {
            $this->assertSame(Irban::where('nama', $nama)->value('id'), User::where('username', $username)->value('irban_id'), $username);
        }

        foreach ([2025, 2026] as $tahun) {
            $pkpt = Pkpt::where('tahun', $tahun)->firstOrFail();
            $this->assertSame(StatusPkpt::Disahkan, $pkpt->status, (string) $tahun);
            $this->assertSame(5, $pkpt->items()->aktif()->distinct()->count('irban_id'), (string) $tahun);
        }

        $perubahan = PkptPerubahan::where('pkpt_id', Pkpt::where('tahun', 2026)->value('id'))->get();
        $disetujui = $perubahan->where('status', StatusPerubahan::Disetujui);
        $this->assertCount(1, $disetujui);
        $this->assertSame('ubah', $disetujui->first()->operasi()->value('aksi')->value);
        $this->assertCount(1, $perubahan->where('status', StatusPerubahan::Diajukan));
    }

    public function test_spt_per_irban_2025_dan_2026(): void
    {
        $this->seedDemo();
        $penanda = app(PenandaSpt::class);
        $semua = array_map(fn (StatusSpt $s) => $s->value, [StatusSpt::Draf, StatusSpt::MenungguNomor, StatusSpt::PerluRevisi, StatusSpt::Ditolak,
            StatusSpt::SiapDilaksanakan, StatusSpt::Pelaksanaan, StatusSpt::PenyusunanLaporan, StatusSpt::Selesai]);

        foreach (self::IRBAN as $nama) {
            $irbanId = Irban::where('nama', $nama)->value('id');

            $lalu = Spt::where('irban_id', $irbanId)->where('status_pengajuan', StatusPengajuan::Diterima)->whereYear('tanggal_mulai', 2025)->get();
            $this->assertGreaterThanOrEqual(8, $lalu->count(), $nama);
            $this->assertSame(0, $lalu->whereNull('ditandai_selesai_pada')->count(), $nama);
            $this->assertSame(0, $lalu->whereNull('nomor_lhp')->count(), $nama);

            // Penyusunan Laporan and Terlambat are counted apart: Terlambat is the flag on a late Penyusunan Laporan.
            $label = $this->label2026($irbanId);
            foreach ([...$semua, 'terlambat'] as $s) {
                $this->assertContains($s, $label, "{$nama}: {$s}");
            }
        }

        $diterima2026 = Spt::where('status_pengajuan', StatusPengajuan::Diterima)->whereYear('tanggal_mulai', 2026)->get();
        $this->assertTrue($diterima2026->contains(fn (Spt $s) => in_array('tidak_sesuai_pkpt', $penanda->untuk($s), true) && $s->kendala()->exists()));
        $khusus = Klasifikasi::where('kode', 'KHUSUS')->value('id');
        $this->assertTrue($diterima2026->contains(fn (Spt $s) => $s->klasifikasi_id === $khusus && $s->pkpt_item_id === null));
        $this->assertGreaterThanOrEqual(1, Komentar::permintaanTerbuka()->count());
    }

    public static function setelahJendelaDemo(): array
    {
        return ['Desember 2026' => ['2026-12-10'], 'Januari 2027' => ['2027-01-05']];
    }

    /**
     * After DEMO_AKHIR the demo is seeded as of that edge, so it no longer throws. Statuses are then read at the real (later) date:
     * Siap Dilaksanakan, Pelaksanaan and Penyusunan Laporan may have moved on, so only the date-stable ones and Terlambat are asserted.
     */
    #[DataProvider('setelahJendelaDemo')]
    public function test_seed_setelah_jendela_demo_tidak_gagal(string $tanggal): void
    {
        $this->seedDemo($tanggal);

        $this->assertSame($tanggal, now('Asia/Jakarta')->toDateString(), 'jam test dipulihkan');
        foreach ([2025, 2026] as $tahun) {
            $this->assertSame(StatusPkpt::Disahkan, Pkpt::where('tahun', $tahun)->firstOrFail()->status, (string) $tahun);
        }
        $perubahan = PkptPerubahan::where('pkpt_id', Pkpt::where('tahun', 2026)->value('id'))->get();
        $this->assertCount(1, $perubahan->where('status', StatusPerubahan::Disetujui));
        $this->assertCount(1, $perubahan->where('status', StatusPerubahan::Diajukan));

        foreach (self::IRBAN as $nama) {
            $label = $this->label2026(Irban::where('nama', $nama)->value('id'));
            foreach ([StatusSpt::Draf->value, StatusSpt::MenungguNomor->value, StatusSpt::PerluRevisi->value, StatusSpt::Ditolak->value,
                StatusSpt::Selesai->value, 'terlambat'] as $s) {
                $this->assertContains($s, $label, "{$nama}: {$s}");
            }
        }
    }

    public function test_realisasi_publik_2025_terisi_tanpa_data_pribadi(): void
    {
        $this->seedDemo();
        $publik = app(RealisasiPublik::class);

        foreach (self::IRBAN as $nama) {
            $this->assertGreaterThan(0, $publik->ringkasan(['tahun' => 2025, 'irban' => Irban::where('nama', $nama)->value('id')])['selesai'], $nama);
        }
        $this->assertNotEmpty($publik->proporsi(['tahun' => 2025], 'jenis'));
        $this->assertCount(5, $publik->proporsi(['tahun' => 2025], 'irban'));
        $this->assertGreaterThan(0, array_sum($publik->perBulan(['tahun' => 2025], false)));

        $html = $this->get('/realisasi?tahun=2025')->assertOk()->getContent();
        $rahasia = Pegawai::pluck('nip')->merge(Pegawai::pluck('nama_bergelar'))
            ->merge(Spt::whereNotNull('nomor_lengkap')->pluck('nomor_lengkap'))->merge(Spt::whereNotNull('nomor_lhp')->pluck('nomor_lhp'));
        $this->assertNotEmpty($rahasia);
        foreach ($rahasia as $r) {
            $this->assertStringNotContainsString(e($r), $html);
        }
    }

    public function test_pdf_yatim_dihapus_setelah_seeding_dan_yang_dirujuk_tetap(): void
    {
        $disk = Storage::disk('local');
        $disk->put('spt/9999/yatim.pdf', 'x');
        $disk->put('lain/aman.txt', 'x');
        $this->seedDemo();

        $this->assertFalse($disk->exists('spt/9999/yatim.pdf'));
        $this->assertTrue($disk->exists('lain/aman.txt'));
        $this->assertGreaterThan(0, SptDokumen::count());
        foreach (SptDokumen::all() as $d) {
            $this->assertTrue($disk->exists($d->path), $d->path);
        }
        $this->assertCount(SptDokumen::count(), $disk->allFiles('spt'));
    }

    public function test_produksi_tidak_menghapus_berkas(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Storage::disk('local')->put('spt/9999/yatim.pdf', 'x');
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists('spt/9999/yatim.pdf'));
    }

    public function test_pdf_demo_valid_dengan_xref(): void
    {
        $this->seedDemo();

        $isi = Storage::disk('local')->get(SptDokumen::firstOrFail()->path);
        $this->assertStringStartsWith('%PDF-', $isi);
        $this->assertStringContainsString('xref', $isi);
        $this->assertStringContainsString('startxref', $isi);
    }

    public function test_personel_spt_irban_i_dari_komposisi_dan_lolos_aturan(): void
    {
        $this->seedDemo();

        $irban = Irban::where('nama', 'Irban I')->firstOrFail();
        $komposisi = IrbanTahunan::where(['irban_id' => $irban->id, 'tahun' => 2026])->firstOrFail();
        $anggota = $komposisi->anggota()->pluck('pegawai.id')->all();
        $spt = Spt::where('irban_id', $irban->id)->has('personel')->with('personel')->get();
        $this->assertNotEmpty($spt);
        foreach ($spt as $s) {
            $this->assertSame($komposisi->ppj_pegawai_id, $s->ppj_pegawai_id, $s->uraian_untuk);
            $baris = $s->personel->map(fn ($p) => ['pegawai_id' => $p->pegawai_id, 'peran' => $p->peran->value])->all();
            $this->assertGreaterThanOrEqual(2, count($baris));
            $hasil = app(ValidatePersonel::class)->cek($baris);
            $this->assertTrue($hasil['ketua'] && $hasil['supervisor'] && $hasil['duplikat']);
            $this->assertEmpty(array_diff(array_column($baris, 'pegawai_id'), $anggota));
        }
    }

    /** 1.1.4 Task 6 (K9): with the demo target_spt filled, the Irban I rekap is no longer above 100% in any quarter or the year. */
    public function test_rekap_demo_irban_i_tidak_di_atas_100_persen(): void
    {
        $this->seedDemo();
        $irban = Irban::where('nama', 'Irban I')->value('id');
        $baris = app(RekapTriwulan::class)->hitung(2026, $irban)['irban']['baris'][0];

        foreach ($baris['tw'] + ['total' => $baris['total']] as $periode => $sel) {
            $this->assertFalse(PembangunRekap::diAtas100($sel['persen']), "Irban I {$periode}: {$sel['realisasi']}/{$sel['rencana']}");
        }
        $this->assertSame(0, app(PembangunRekap::class)->tanpaTarget(2026, 0, $irban));
    }

    public function test_warna_jenis_demo_stabil_per_nama_bukan_jumlah_baris(): void
    {
        JenisPenugasan::factory()->count(2)->create(); // shifts count(); the swatch must not move
        $this->seedDemo();

        $nama = array_values(array_unique(array_column((new \ReflectionClassConstant(PkptIrbanIDemo2026Seeder::class, 'ROWS'))->getValue(), 2)));
        $i = array_search('Reviu DBH', $nama);
        $this->assertSame(ColorRules::SWATCHES[$i % count(ColorRules::SWATCHES)]['hex'], JenisPenugasan::where('nama', 'Reviu DBH')->value('warna'));
    }

    public function test_produksi_tanpa_demo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        foreach ([User::class, Spt::class, Pkpt::class, Tim::class, IrbanWilayah::class, Pegawai::class] as $model) {
            $this->assertSame(0, $model::count(), $model);
        }
        $this->assertSame(5, Irban::count());
        $this->assertGreaterThan(0, Objek::count());
        $this->assertGreaterThan(0, JenisPenugasan::count());
        $this->assertSame(5, Klasifikasi::count());
    }

    public function test_kinerja_seeder_menolak_produksi(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('db:seed', ['--class' => KinerjaSeeder::class, '--force' => true])
            ->expectsOutputToContain('KinerjaSeeder hanya untuk pengukuran lokal; tidak dijalankan di produksi.')
            ->assertExitCode(0);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Spt::count());
    }
}
