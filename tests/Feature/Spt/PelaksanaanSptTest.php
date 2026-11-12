<?php

namespace Tests\Feature\Spt;

use App\Actions\MasterData\SaveKlasifikasi;
use App\Actions\Spt\SimpanKendala;
use App\Actions\Spt\TandaiSelesai;
use App\Actions\Spt\UbahTanggalRealisasi;
use App\Actions\Spt\UnggahDokumenSpt;
use App\Enums\KejadianSpt;
use App\Enums\StatusSpt;
use App\Enums\SyaratSelesai;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Klasifikasi;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\SptDokumen;
use App\Models\User;
use App\Services\PenandaSpt;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfUji;
use Tests\TestCase;

class PelaksanaanSptTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $user;

    private Pegawai $penginput;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-03-25 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create();
        $this->user = $this->irbanUser($this->irban);
        $this->penginput = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
    }

    private function irbanUser(Irban $irban): User
    {
        $u = User::factory()->irban()->create();
        $u->forceFill(['irban_id' => $irban->id])->save();

        return $u;
    }

    private function spt(string $kode = 'KHUSUS', array $attrs = []): Spt
    {
        return Spt::factory()->diterima()->create([
            'irban_id' => $this->irban->id, 'klasifikasi_id' => Klasifikasi::where('kode', $kode)->value('id'),
            'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20', ...$attrs,
        ]);
    }

    private function pdf(string $nama = 'x.pdf', int $kb = 10): UploadedFile
    {
        return PdfUji::buat($nama, $kb);
    }

    private function gagal(callable $fn, string $key, ?string $pesan = null): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            if ($pesan !== null) {
                $this->assertSame($pesan, $e->errors()[$key][0]);
            }
        }
    }

    private function ubah(Spt $spt, array $over = [], ?User $user = null): Spt
    {
        return app(UbahTanggalRealisasi::class)->handle($user ?? $this->user, $spt, [
            'tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-25', 'alasan' => 'Menyesuaikan jadwal pemeriksaan.', 'diinput_oleh' => $this->penginput->id, ...$over,
        ]);
    }

    private function selesai(Spt $spt, array $over = [], ?UploadedFile $berkas = null): Spt
    {
        return app(TandaiSelesai::class)->handle($this->user, $spt, ['diinput_oleh' => $this->penginput->id, ...$over], $berkas);
    }

    // Policy

    public function test_laksanakan_matrix(): void
    {
        $spt = $this->spt();
        $this->assertTrue(Gate::forUser($this->user)->allows('laksanakan', $spt));
        foreach ([
            $this->irbanUser(Irban::factory()->create()),
            User::factory()->bagianUmum()->create(),
            User::factory()->pimpinan()->create(),
            User::factory()->admin()->create(),
        ] as $lain) {
            $this->assertFalse(Gate::forUser($lain)->allows('laksanakan', $spt));
        }
        $this->assertFalse(Gate::forUser($this->user)->allows('laksanakan', Spt::factory()->create(['irban_id' => $this->irban->id])), 'draf');
        foreach (['menungguNomor', 'perluRevisi', 'ditolak', 'dibatalkan', 'selesai'] as $state) {
            $s = Spt::factory()->{$state}()->create(['irban_id' => $this->irban->id]);
            $this->assertFalse(Gate::forUser($this->user)->allows('laksanakan', $s), $state);
        }
    }

    // UbahTanggalRealisasi

    public function test_ubah_tanggal_menyimpan_dan_mencatat_riwayat(): void
    {
        $spt = $this->ubah($this->spt());

        $this->assertSame('2026-03-05', $spt->fresh()->tanggal_mulai->toDateString());
        $this->assertSame('2026-03-25', $spt->fresh()->tanggal_selesai->toDateString());
        $r = $spt->riwayat()->where('kejadian', KejadianSpt::TANGGAL_DIUBAH)->sole();
        $this->assertSame(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20'], $r->nilai_lama);
        $this->assertSame(['tanggal_mulai' => '2026-03-05', 'tanggal_selesai' => '2026-03-25'], $r->nilai_baru);
        $this->assertSame('Menyesuaikan jadwal pemeriksaan.', $r->alasan);
        $this->assertSame($this->user->id, $r->oleh);
        $this->assertSame($this->penginput->id, $r->diinput_oleh);
        $this->assertSame($this->penginput->id, session('diinput_oleh.'.$this->irban->id));
    }

    public function test_ubah_tanggal_aturan(): void
    {
        $spt = $this->spt();
        $this->gagal(fn () => $this->ubah($spt, ['diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->gagal(fn () => $this->ubah($spt, ['diinput_oleh' => Pegawai::factory()->create()->id]), 'diinput_oleh', 'Penginput harus PPJ atau anggota aktif Irban ini tahun 2026.');
        $this->gagal(fn () => $this->ubah($spt, ['tanggal_mulai' => '']), 'tanggal_mulai');
        $this->gagal(fn () => $this->ubah($spt, ['tanggal_selesai' => 'bukan']), 'tanggal_selesai');
        $this->gagal(fn () => $this->ubah($spt, ['tanggal_mulai' => '2026-03-10', 'tanggal_selesai' => '2026-03-09']), 'tanggal_selesai', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        $this->gagal(fn () => $this->ubah($spt, ['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']), 'tanggal_mulai', 'Tanggal realisasi tidak berubah.');
        $this->gagal(fn () => $this->ubah($spt, ['alasan' => 'pendek']), 'alasan', 'Alasan minimal 10 karakter.');
        $this->gagal(fn () => $this->ubah($spt, ['alasan' => str_repeat('a', 1001)]), 'alasan');
        $this->assertSame(0, $spt->riwayat()->count());
    }

    public function test_ubah_tanggal_ditolak_selain_irban_pemilik_dan_pesan_selesai(): void
    {
        $spt = $this->spt();
        foreach ([$this->irbanUser(Irban::factory()->create()), User::factory()->pimpinan()->create(), User::factory()->admin()->create()] as $lain) {
            try {
                $this->ubah($spt, [], $lain);
                $this->fail('AuthorizationException expected');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->gagal(fn () => $this->ubah($this->spt('KHUSUS', ['ditandai_selesai_pada' => now(), 'ditandai_selesai_oleh' => $this->user->id])), 'spt', 'SPT ini sudah Selesai dan tidak dapat diubah.');
        $this->expectException(AuthorizationException::class);
        $this->ubah(Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]));
    }

    public function test_ubah_tanggal_membuat_dan_menghapus_penanda_tidak_sesuai(): void
    {
        $item = PkptItem::factory()->create(['irban_id' => $this->irban->id, 'tanggal_mulai' => '2026-03-01', 'tanggal_selesai' => '2026-03-31']);
        $spt = $this->spt('KHUSUS', ['pkpt_item_id' => $item->id]);
        $penanda = fn () => app(PenandaSpt::class)->untuk($spt->fresh());

        $this->assertNotContains('tidak_sesuai_pkpt', $penanda());
        $this->ubah($spt, ['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-04-10']);
        $this->assertContains('tidak_sesuai_pkpt', $penanda());
        $this->ubah($spt, ['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-21']);
        $this->assertNotContains('tidak_sesuai_pkpt', $penanda());
    }

    // UnggahDokumenSpt

    private function unggah(Spt $spt, ?UploadedFile $f = null, array $over = []): SptDokumen
    {
        return app(UnggahDokumenSpt::class)->handle($this->user, $spt, ['diinput_oleh' => $this->penginput->id, ...$over], $f ?? $this->pdf('final.pdf'));
    }

    public function test_unggah_final_menyimpan_berkas_hash_dan_riwayat(): void
    {
        $spt = $this->spt();
        $this->assertContains('spt_final_belum_diupload', app(PenandaSpt::class)->untuk($spt));
        $f = $this->pdf('final.pdf');
        $d = $this->unggah($spt, $f);

        Storage::disk('local')->assertExists($d->path);
        $this->assertMatchesRegularExpression("#^spt/{$spt->id}/[0-9a-f-]{36}\\.pdf$#", $d->path);
        $this->assertSame('final', $d->jenis);
        $this->assertSame(hash_file('sha256', $f->getRealPath()), $d->sha256);
        $this->assertSame($this->user->id, $d->diunggah_oleh);
        $this->assertSame($this->penginput->id, $d->diinput_oleh);
        $r = $spt->riwayat()->where('kejadian', KejadianSpt::FINAL_DIUNGGAH)->sole();
        $this->assertSame(['nama_asli' => 'final.pdf', 'sha256' => $d->sha256], $r->nilai_baru);
        $this->assertNotContains('spt_final_belum_diupload', app(PenandaSpt::class)->untuk($spt->fresh()));
    }

    public function test_unggah_kedua_menambah_baris_dan_mempertahankan_yang_pertama(): void
    {
        $spt = $this->spt();
        $a = $this->unggah($spt);
        $b = $this->unggah($spt);

        $this->assertSame(2, $spt->dokumen()->where('jenis', 'final')->count());
        Storage::disk('local')->assertExists($a->path);
        Storage::disk('local')->assertExists($b->path);
    }

    public function test_unggah_menolak_bukan_pdf_terlalu_besar_dan_kosong(): void
    {
        $spt = $this->spt();
        $this->gagal(fn () => $this->unggah($spt, UploadedFile::fake()->create('x.png', 10, 'image/png')), 'berkas', 'Dokumen harus PDF dan maksimal 10 MB.');
        $this->gagal(fn () => $this->unggah($spt, UploadedFile::fake()->create('x.pdf', 10, 'image/png')), 'berkas');
        $this->gagal(fn () => $this->unggah($spt, $this->pdf('besar.pdf', 11 * 1024)), 'berkas', 'Dokumen harus PDF dan maksimal 10 MB.');
        $this->gagal(fn () => app(UnggahDokumenSpt::class)->handle($this->user, $spt, ['diinput_oleh' => $this->penginput->id], null), 'berkas');
        $this->gagal(fn () => $this->unggah($spt, null, ['diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->assertSame(0, $spt->dokumen()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_berkas_dihapus_bila_insert_dokumen_gagal(): void
    {
        $spt = $this->spt();
        SptDokumen::creating(fn () => throw new \RuntimeException('gagal'));

        $this->assertThrows(fn () => $this->unggah($spt), \RuntimeException::class, 'gagal');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $spt->riwayat()->count());
    }

    public function test_dokumen_baru_dapat_diunduh_sesuai_otorisasi(): void
    {
        $spt = $this->spt('KHUSUS');
        $this->unggah($spt);
        $this->selesai($spt, ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-22'], $this->pdf('cover.pdf'));
        $opsional = $this->spt('LAIN_LAIN');
        $this->selesai($opsional, [], $this->pdf('lap.pdf'));
        $this->assertEqualsCanonicalizing(['final', 'cover_lhp'], $spt->dokumen()->pluck('jenis')->all());

        foreach ([$spt, $opsional] as $s) {
            foreach ($s->dokumen as $dok) {
                $url = route('spt.dokumen', [$s, $dok]);
                $this->app['auth']->forgetGuards();
                $this->get($url)->assertRedirect(route('login'));
                foreach ([$this->user, User::factory()->bagianUmum()->create(), User::factory()->pimpinan()->create()] as $boleh) {
                    $this->actingAs($boleh)->get($url)->assertOk();
                }
                $this->actingAs($this->irbanUser(Irban::factory()->create()))->get($url)->assertForbidden();
            }
        }
        $this->assertSame('laporan', $opsional->dokumen()->sole()->jenis);
    }

    public function test_berkas_selesai_melebihi_batas_ditolak(): void
    {
        $this->gagal(fn () => $this->selesai($this->spt('KHUSUS'), ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-22'], $this->pdf('besar.pdf', 11 * 1024)), 'berkas', 'Dokumen harus PDF dan maksimal 10 MB.');
        $this->gagal(fn () => $this->selesai($this->spt('LAIN_LAIN'), [], $this->pdf('besar.pdf', 11 * 1024)), 'berkas', 'Dokumen harus PDF dan maksimal 10 MB.');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_tanggal_lhp_tanpa_nol_ditolak(): void
    {
        $spt = $this->spt('LAIN_LAIN');
        $this->gagal(fn () => $this->selesai($spt, ['tanggal_lhp' => '2026-3-1']), 'tanggal_lhp');
        $this->gagal(fn () => $this->selesai($spt, ['tanggal_lhp' => '2026-3-5']), 'tanggal_lhp', 'Isi tanggal LHP.');
        // Non-canonical input is refused, never rolled over or trimmed into another date (2026-02-31 would become 3 March).
        foreach (['2026-02-31', ' 2026-03-05', '2026-03-05 ', '2026-03-05T00:00', '05-03-2026'] as $v) {
            $this->gagal(fn () => $this->selesai($spt, ['tanggal_lhp' => $v]), 'tanggal_lhp', 'Isi tanggal LHP.');
        }
        $this->assertNull($spt->fresh()->ditandai_selesai_pada);
        $this->selesai($spt, ['tanggal_lhp' => '2026-03-05']);
        $this->assertSame('2026-03-05', $spt->fresh()->tanggal_lhp->toDateString());
    }

    public function test_batas_ukuran_mengikuti_pengaturan(): void
    {
        $spt = $this->spt();
        app(Settings::class)->set('upload.maks_mb', 1);

        $this->gagal(fn () => $this->unggah($spt, $this->pdf('dua.pdf', 2 * 1024)), 'berkas', 'Dokumen harus PDF dan maksimal 1 MB.');
        $this->assertSame('final', $this->unggah($spt, $this->pdf('kecil.pdf', 500))->jenis);
    }

    public function test_unggah_ditolak_untuk_spt_selesai_dan_jenis_lain(): void
    {
        $this->gagal(fn () => $this->unggah($this->spt('KHUSUS', ['ditandai_selesai_pada' => now()])), 'spt', 'SPT ini sudah Selesai dan tidak dapat diubah.');
        $this->expectException(\InvalidArgumentException::class);
        app(UnggahDokumenSpt::class)->handle($this->user, $this->spt(), ['diinput_oleh' => $this->penginput->id], $this->pdf(), 'laporan');
    }

    // SimpanKendala

    private function kendala(Spt $spt, array $over = [], ?User $user = null)
    {
        return app(SimpanKendala::class)->handle($user ?? $this->user, $spt, [
            'kategori' => 'Objek tidak siap', 'uraian' => 'Objek belum siap diperiksa.', 'diinput_oleh' => $this->penginput->id, ...$over,
        ]);
    }

    public function test_kendala_disimpan_lalu_diubah_di_tempat(): void
    {
        $spt = $this->spt();
        $k = $this->kendala($spt);
        $this->assertSame($this->user->id, $k->dibuat_oleh);
        $this->assertSame($this->penginput->id, $k->diinput_oleh);

        $this->kendala($spt, ['kategori' => 'Lainnya', 'uraian' => 'Uraian kendala yang diperbarui.']);
        $this->assertSame(1, $spt->kendala()->count());
        $this->assertSame('Lainnya', $spt->kendala()->first()->kategori);

        [$pertama, $kedua] = $spt->riwayat()->where('kejadian', KejadianSpt::KENDALA_DIISI)->orderBy('id')->get()->all();
        $this->assertNull($pertama->nilai_lama);
        $this->assertSame(['kategori' => 'Objek tidak siap', 'uraian' => 'Objek belum siap diperiksa.'], $pertama->nilai_baru);
        $this->assertSame(['kategori' => 'Objek tidak siap', 'uraian' => 'Objek belum siap diperiksa.'], $kedua->nilai_lama);
        $this->assertSame(['kategori' => 'Lainnya', 'uraian' => 'Uraian kendala yang diperbarui.'], $kedua->nilai_baru);
        $this->assertSame($this->penginput->id, $kedua->diinput_oleh);
    }

    public function test_kendala_aturan(): void
    {
        $spt = $this->spt();
        $this->gagal(fn () => $this->kendala($spt, ['kategori' => 'Tidak ada']), 'kategori', 'Pilih kategori kendala.');
        $this->gagal(fn () => $this->kendala($spt, ['kategori' => '']), 'kategori');
        $this->gagal(fn () => $this->kendala($spt, ['uraian' => 'pendek']), 'uraian', 'Uraian kendala minimal 10 karakter.');
        $this->gagal(fn () => $this->kendala($spt, ['uraian' => str_repeat('a', 2001)]), 'uraian');
        $this->gagal(fn () => $this->kendala($spt, ['diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->gagal(fn () => $this->kendala($this->spt('KHUSUS', ['ditandai_selesai_pada' => now()])), 'spt');
        $this->assertSame(0, $spt->kendala()->count());
    }

    public function test_kategori_kendala_mengikuti_pengaturan(): void
    {
        $spt = $this->spt();
        app(Settings::class)->set('kendala.kategori', ['Hujan deras']);
        $this->gagal(fn () => $this->kendala($spt), 'kategori');
        $this->assertSame('Hujan deras', $this->kendala($spt, ['kategori' => 'Hujan deras'])->kategori);
    }

    public function test_kendala_ditolak_untuk_non_pemilik(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->kendala($this->spt(), [], User::factory()->admin()->create());
    }

    // TandaiSelesai

    #[DataProvider('lhpLengkap')]
    public function test_lhp_lengkap_ditolak_tanpa_kelengkapan(string $kode): void
    {
        $spt = $this->spt($kode);
        $this->assertSame(SyaratSelesai::LhpLengkap, app(TandaiSelesai::class)->syarat($spt));
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-22']), 'berkas', 'Unggah cover LHP (PDF).');
        $this->gagal(fn () => $this->selesai($spt, ['tanggal_lhp' => '2026-03-22'], $this->pdf()), 'nomor_lhp', 'Isi nomor LHP.');
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => 'LHP/1'], $this->pdf()), 'tanggal_lhp', 'Isi tanggal LHP.');
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-01'], $this->pdf()), 'tanggal_lhp', 'Tanggal LHP tidak boleh sebelum tanggal mulai SPT.');
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-26'], $this->pdf()), 'tanggal_lhp', 'Tanggal LHP tidak boleh di masa depan.');
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => str_repeat('a', 101), 'tanggal_lhp' => '2026-03-22'], $this->pdf()), 'nomor_lhp');
        $this->gagal(fn () => $this->selesai($spt, ['nomor_lhp' => 'LHP/1', 'tanggal_lhp' => '2026-03-22'], UploadedFile::fake()->create('x.png', 5, 'image/png')), 'berkas');
        $this->assertNotSame(StatusSpt::Selesai, $spt->fresh()->status());
        $this->assertSame(0, $spt->dokumen()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function lhpLengkap(): array
    {
        return ['REGULER' => ['REGULER'], 'KHUSUS' => ['KHUSUS']];
    }

    #[DataProvider('lhpLengkap')]
    public function test_lhp_lengkap_diterima_dengan_semua(string $kode): void
    {
        $spt = $this->spt($kode);
        $out = $this->selesai($spt, ['nomor_lhp' => 'LHP/7/2026', 'tanggal_lhp' => '2026-03-22'], $this->pdf('cover.pdf'));

        $spt = $spt->fresh();
        $this->assertSame(StatusSpt::Selesai, $spt->status());
        $this->assertTrue($out->is($spt));
        $this->assertSame('LHP/7/2026', $spt->nomor_lhp);
        $this->assertSame('2026-03-22', $spt->tanggal_lhp->toDateString());
        $this->assertSame($this->user->id, $spt->ditandai_selesai_oleh);
        $this->assertSame('2026-03-25', $spt->ditandai_selesai_pada->toDateString());
        $d = $spt->dokumen()->sole();
        $this->assertSame('cover_lhp', $d->jenis);
        Storage::disk('local')->assertExists($d->path);
        $r = $spt->riwayat()->where('kejadian', KejadianSpt::DITANDAI_SELESAI)->sole();
        $this->assertSame(['syarat' => 'lhp_lengkap', 'nomor_lhp' => 'LHP/7/2026', 'tanggal_lhp' => '2026-03-22'], $r->nilai_baru);
        $this->assertSame($this->user->id, $r->oleh);
        $this->assertSame($this->penginput->id, $r->diinput_oleh);
    }

    public function test_konfirmasi_diklat(): void
    {
        $spt = $this->spt('DIKLAT');
        $this->assertSame(SyaratSelesai::Konfirmasi, app(TandaiSelesai::class)->syarat($spt));
        $this->gagal(fn () => $this->selesai($spt), 'konfirmasi', 'Centang konfirmasi bahwa penugasan telah selesai.');
        $this->gagal(fn () => $this->selesai($spt, ['konfirmasi' => false]), 'konfirmasi');

        $this->selesai($spt, ['konfirmasi' => true]);
        $this->assertSame(StatusSpt::Selesai, $spt->fresh()->status());
        $this->assertSame(0, $spt->dokumen()->count());
        $this->assertSame(['syarat' => 'konfirmasi', 'nomor_lhp' => null, 'tanggal_lhp' => null], $spt->riwayat()->where('kejadian', KejadianSpt::DITANDAI_SELESAI)->sole()->nilai_baru);
    }

    #[DataProvider('opsional')]
    public function test_opsional_diterima_tanpa_dan_dengan_pdf(string $kode): void
    {
        $kosong = $this->spt($kode);
        $this->selesai($kosong);
        $this->assertSame(StatusSpt::Selesai, $kosong->fresh()->status());
        $this->assertSame(0, $kosong->dokumen()->count());

        $isi = $this->spt($kode);
        $this->selesai($isi, ['nomor_lhp' => 'LHP/9', 'tanggal_lhp' => '2026-03-24'], $this->pdf('laporan.pdf'));
        $this->assertSame('laporan', $isi->dokumen()->sole()->jenis);
        $this->assertSame('LHP/9', $isi->fresh()->nomor_lhp);

        $buruk = $this->spt($kode);
        $this->gagal(fn () => $this->selesai($buruk, [], UploadedFile::fake()->create('x.png', 5, 'image/png')), 'berkas');
        $this->gagal(fn () => $this->selesai($buruk, ['tanggal_lhp' => '2026-03-01']), 'tanggal_lhp');
        $this->gagal(fn () => $this->selesai($buruk, ['tanggal_lhp' => '2026-03-30']), 'tanggal_lhp');
    }

    public static function opsional(): array
    {
        return ['LUAR DAERAH' => ['LUAR_DAERAH'], 'LAIN-LAIN' => ['LAIN_LAIN']];
    }

    public function test_perubahan_syarat_oleh_admin_berlaku(): void
    {
        $khusus = Klasifikasi::where('kode', 'KHUSUS')->first();
        $spt = $this->spt('KHUSUS');
        $this->gagal(fn () => $this->selesai($spt), 'berkas');

        app(SaveKlasifikasi::class)->handle($khusus, ['nama' => $khusus->nama, 'wajibItemPkpt' => $khusus->wajib_item_pkpt, 'syaratSelesai' => 'konfirmasi', 'tampilPublik' => $khusus->tampil_publik]);
        $this->assertSame(SyaratSelesai::Konfirmasi, app(TandaiSelesai::class)->syarat($spt->fresh()));
        $this->gagal(fn () => $this->selesai($spt->fresh()), 'konfirmasi');
        $this->selesai($spt->fresh(), ['konfirmasi' => true]);
        $this->assertSame(StatusSpt::Selesai, $spt->fresh()->status());
    }

    public function test_selesai_diblokir_sebelum_mulai_dan_saat_perlu_kendala(): void
    {
        $belum = $this->spt('DIKLAT', ['tanggal_mulai' => '2026-03-26', 'tanggal_selesai' => '2026-03-30']);
        $this->assertSame('SPT belum dilaksanakan.', app(TandaiSelesai::class)->bisa($belum));
        $this->gagal(fn () => $this->selesai($belum, ['konfirmasi' => true]), 'spt', 'SPT belum dilaksanakan.');

        $item = PkptItem::factory()->create(['irban_id' => $this->irban->id, 'tanggal_mulai' => '2026-03-01', 'tanggal_selesai' => '2026-03-10']);
        $spt = $this->spt('DIKLAT', ['pkpt_item_id' => $item->id]);
        $pesan = 'Isi kendala terlebih dahulu karena SPT Tidak Sesuai PKPT.';
        $this->assertSame($pesan, app(TandaiSelesai::class)->bisa($spt));
        $this->gagal(fn () => $this->selesai($spt, ['konfirmasi' => true]), 'spt', $pesan);

        $this->kendala($spt);
        $this->assertNull(app(TandaiSelesai::class)->bisa($spt->fresh()));
        $this->selesai($spt->fresh(), ['konfirmasi' => true]);
        $this->assertSame(StatusSpt::Selesai, $spt->fresh()->status());
    }

    public function test_tandai_selesai_ditolak_untuk_non_pemilik_dan_tidak_bisa_dua_kali(): void
    {
        $spt = $this->spt('DIKLAT');
        try {
            app(TandaiSelesai::class)->handle(User::factory()->pimpinan()->create(), $spt, ['konfirmasi' => true, 'diinput_oleh' => $this->penginput->id]);
            $this->fail('AuthorizationException expected');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->selesai($spt, ['konfirmasi' => true]);
        $this->gagal(fn () => $this->selesai($spt, ['konfirmasi' => true]), 'spt', 'SPT ini sudah Selesai dan tidak dapat diubah.');
        $this->assertSame(1, $spt->riwayat()->where('kejadian', KejadianSpt::DITANDAI_SELESAI)->count());
    }

    public function test_tandai_selesai_butuh_penginput(): void
    {
        $this->gagal(fn () => $this->selesai($this->spt('DIKLAT'), ['konfirmasi' => true, 'diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
    }
}
