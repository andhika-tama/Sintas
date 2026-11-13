<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\UbahRealisasiDana;
use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Livewire\Spt\Detail;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Pegawai;
use App\Models\Spt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Versi 1.3 Task 3 (K4-K6): realisasi dana per SPT, ability ubahDana, cegah(), riwayat lama/baru, the detail modal. */
class RealisasiDanaTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $irbanUser;

    private Pegawai $penginput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-03-25 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create();
        $this->irbanUser = $this->userIrban($this->irban);
        $this->penginput = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
    }

    private function userIrban(Irban $irban): User
    {
        $u = User::factory()->irban()->create();
        $u->forceFill(['irban_id' => $irban->id])->save();

        return $u;
    }

    /** Default: Diterima, ended 2026-03-20, today 2026-03-25 = Penyusunan Laporan. */
    private function spt(string $state = 'diterima', array $attrs = []): Spt
    {
        return Spt::factory()->{$state}()->create(['irban_id' => $this->irban->id, 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20', ...$attrs]);
    }

    private function detail(Spt $spt, ?User $u = null)
    {
        return Livewire::actingAs($u ?? $this->irbanUser)->test(Detail::class, ['spt' => $spt]);
    }

    private function ubah(Spt $spt, mixed $dana, ?User $user = null, array $over = []): Spt
    {
        return app(UbahRealisasiDana::class)->handle($user ?? $this->irbanUser, $spt, ['realisasi_dana' => $dana, 'diinput_oleh' => $this->penginput->id, ...$over]);
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

    private function danaRiwayat(Spt $spt)
    {
        return $spt->riwayat()->where('kejadian', KejadianSpt::DANA_DIUBAH)->orderBy('id')->get();
    }

    public function test_ability_hanya_irban_pemilik(): void
    {
        $spt = $this->spt();
        $lain = $this->userIrban(Irban::factory()->create());
        $this->assertTrue(Gate::forUser($this->irbanUser)->allows('ubahDana', $spt));
        $this->assertFalse(Gate::forUser($lain)->allows('ubahDana', $spt));
        foreach ([Role::BagianUmum, Role::Pimpinan, Role::Admin] as $peran) {
            $this->assertFalse(Gate::forUser(User::factory()->create(['role' => $peran]))->allows('ubahDana', $spt), $peran->value);
        }
    }

    public function test_cegah_mengikuti_status(): void
    {
        $aksi = app(UbahRealisasiDana::class);
        $this->assertNull($aksi->cegah($this->spt()));          // Penyusunan Laporan
        $this->assertNull($aksi->cegah($this->spt('selesai'))); // Selesai
        $belum = 'Realisasi dana dapat diisi setelah pelaksanaan berakhir.';
        $this->assertSame($belum, $aksi->cegah($this->spt('diterima', ['tanggal_mulai' => '2026-03-23', 'tanggal_selesai' => '2026-03-30']))); // Pelaksanaan
        $this->assertSame($belum, $aksi->cegah($this->spt('diterima', ['tanggal_mulai' => '2026-04-02', 'tanggal_selesai' => '2026-04-09']))); // Siap Dilaksanakan
        $this->assertSame(UbahRealisasiDana::DIBATALKAN, $aksi->cegah($this->spt('dibatalkan')));
        $this->assertSame(UbahRealisasiDana::BELUM_TERBIT, $aksi->cegah($this->spt('menungguNomor')));
    }

    public function test_irban_mengisi_saat_penyusunan_laporan_dan_riwayat_tercatat(): void
    {
        $spt = $this->spt();
        $hasil = $this->ubah($spt, 'Rp 1.500.000,50');
        $this->assertSame(1500000, $hasil->realisasi_dana);
        $this->assertSame(1500000, $spt->fresh()->realisasi_dana);
        $r = $this->danaRiwayat($spt)->sole();
        $this->assertSame(['realisasi_dana' => null], $r->nilai_lama);
        $this->assertSame(['realisasi_dana' => 1500000], $r->nilai_baru);
        $this->assertSame($this->irbanUser->id, $r->oleh);
        $this->assertSame($this->penginput->id, $r->diinput_oleh);

        $this->ubah($spt, '2000000');
        $this->assertSame(['realisasi_dana' => 1500000], $this->danaRiwayat($spt)->last()->nilai_lama);
        $this->assertSame(['realisasi_dana' => 2000000], $this->danaRiwayat($spt)->last()->nilai_baru);
    }

    public function test_irban_mengisi_saat_selesai_nol_berbeda_dari_kosong_dan_bisa_dikosongkan(): void
    {
        $spt = $this->spt('selesai');
        $this->ubah($spt, '0');
        $this->assertSame(0, $spt->fresh()->realisasi_dana);
        $this->ubah($spt, '');
        $this->assertNull($spt->fresh()->realisasi_dana);
        $this->assertSame(['realisasi_dana' => 0], $this->danaRiwayat($spt)->last()->nilai_lama);
        $this->assertSame(['realisasi_dana' => null], $this->danaRiwayat($spt)->last()->nilai_baru);
        $this->assertCount(2, $this->danaRiwayat($spt));
    }

    public function test_nilai_sama_ditolak_tanpa_riwayat(): void
    {
        $spt = $this->spt();
        $this->gagal(fn () => $this->ubah($spt, ''), 'realisasi_dana', 'Realisasi dana tidak berubah.');
        $this->ubah($spt, '600000');
        $this->gagal(fn () => $this->ubah($spt, 'Rp600.000'), 'realisasi_dana', 'Realisasi dana tidak berubah.');
        $this->assertCount(1, $this->danaRiwayat($spt));
    }

    public function test_kolom_tidak_fillable(): void
    {
        $spt = $this->spt();
        $spt->fill(['realisasi_dana' => 5])->save();
        $this->assertNull($spt->fresh()->realisasi_dana);
    }

    public function test_diinput_oleh_wajib(): void
    {
        $spt = $this->spt();
        $this->gagal(fn () => $this->ubah($spt, '100', over: ['diinput_oleh' => null]), 'diinput_oleh');
        $this->gagal(fn () => $this->ubah($spt, '100', over: ['diinput_oleh' => Pegawai::factory()->create()->id]), 'diinput_oleh');
        $this->assertNull($spt->fresh()->realisasi_dana);
    }

    public function test_ditolak_untuk_selain_irban_pemilik(): void
    {
        $spt = $this->spt();
        foreach ([$this->userIrban(Irban::factory()->create()), User::factory()->create(['role' => Role::BagianUmum]), User::factory()->create(['role' => Role::Pimpinan]), User::factory()->create(['role' => Role::Admin])] as $u) {
            try {
                $this->ubah($spt, '100', $u);
                $this->fail('AuthorizationException expected');
            } catch (AuthorizationException) {
                $this->assertNull($spt->fresh()->realisasi_dana);
            }
        }
    }

    public function test_endpoint_livewire_menolak_irban_lain_dan_peran_lain(): void
    {
        $spt = $this->spt();
        $lain = $this->userIrban(Irban::factory()->create());
        // Mounted as the owner, then the session user switches: the action must authorize again (no route middleware on /livewire/update).
        $komponen = $this->detail($spt)->call('buka', 'dana')->set('realisasi_dana', '999')->set('diinput_oleh', $this->penginput->id);
        $this->actingAs($lain);
        $komponen->call('simpanDana')->assertForbidden();
        $this->assertNull($spt->fresh()->realisasi_dana);

        $pimpinan = User::factory()->create(['role' => Role::Pimpinan]);
        $this->detail($spt, $pimpinan)->call('buka', 'dana')->assertForbidden();
        $this->detail($spt, $pimpinan)->set('realisasi_dana', '999')->call('simpanDana')->assertForbidden();
        $bu = User::factory()->create(['role' => Role::BagianUmum]);
        $this->detail($spt, $bu)->call('simpanDana')->assertForbidden();
        $this->assertNull($spt->fresh()->realisasi_dana);
    }

    public function test_panel_tampil_untuk_semua_peran_kontrol_hanya_pemilik(): void
    {
        $spt = $this->spt('diterima', ['realisasi_dana' => 600000]);
        $this->detail($spt)->assertSee('Realisasi Dana')->assertSee('Rp600.000')->assertSee('Ubah Realisasi Dana');
        foreach ([Role::BagianUmum, Role::Pimpinan, Role::Admin] as $peran) {
            $this->detail($spt, User::factory()->create(['role' => $peran]))->assertSee('Realisasi Dana')->assertSee('Rp600.000')->assertDontSee('Ubah Realisasi Dana');
        }
        $kosong = $this->spt();
        $this->detail($kosong)->assertSee('Isi Realisasi Dana');
        $this->detail($kosong, User::factory()->create(['role' => Role::Pimpinan]))->assertSee('Belum diisi')->assertDontSee('Isi Realisasi Dana');
    }

    public function test_pemilik_sebelum_pelaksanaan_berakhir_melihat_alasan_bukan_kontrol(): void
    {
        $spt = $this->spt('diterima', ['tanggal_mulai' => '2026-03-23', 'tanggal_selesai' => '2026-03-30']);
        $this->detail($spt)->assertSee('Realisasi dana dapat diisi setelah pelaksanaan berakhir.')->assertDontSee('Isi Realisasi Dana')
            ->call('buka', 'dana')->assertDispatched('toast', type: 'error', message: 'Realisasi dana dapat diisi setelah pelaksanaan berakhir.')
            ->assertSet('modal', '');
    }

    public function test_simpan_dari_modal_dan_riwayat_tampil(): void
    {
        $spt = $this->spt();
        $this->detail($spt)->call('buka', 'dana')->assertSet('modal', 'dana')->assertSet('realisasi_dana', '')->assertSeeHtml('inputmode="numeric"')
            ->set('realisasi_dana', '600000')->set('diinput_oleh', $this->penginput->id)->call('simpanDana')
            ->assertHasNoErrors()->assertSet('modal', '')->assertDispatched('toast', type: 'success', message: 'Realisasi dana disimpan')
            ->assertSee('Realisasi dana diubah')->assertSee('– → Rp600.000');
        $this->assertSame(600000, $spt->fresh()->realisasi_dana);
        $this->detail($spt)->call('buka', 'dana')->assertSet('realisasi_dana', '600000');
    }

    /** Final review 2 (server half): reopening after an unsaved edit resets the model to the stored value; the visible text follows through x-modelable (only the browser proves that, see decisions Fase 13). */
    public function test_buka_ulang_mereset_isian_ke_nilai_tersimpan(): void
    {
        $spt = $this->spt('diterima', ['realisasi_dana' => 600000]);
        $this->detail($spt)->call('buka', 'dana')->assertSet('realisasi_dana', '600000')->assertSeeHtml('x-modelable="digit"')
            ->set('realisasi_dana', '700000')->call('buka', 'dana')->assertSet('realisasi_dana', '600000');
    }

    public function test_modal_menolak_tanpa_penginput_dan_nilai_tak_berubah_sebagai_galat_field(): void
    {
        $spt = $this->spt();
        $this->detail($spt)->call('buka', 'dana')->set('realisasi_dana', '100')->set('diinput_oleh', null)->call('simpanDana')->assertHasErrors(['diinput_oleh'])->assertSet('modal', 'dana');
        $this->detail($spt)->call('buka', 'dana')->set('diinput_oleh', $this->penginput->id)->call('simpanDana')
            ->assertHasErrors(['realisasi_dana'])->assertSee('Realisasi dana tidak berubah.')->assertSet('modal', 'dana');
    }

    /** Review Focus 3: odd typed/pasted values are field errors (or normalised), never a 500, and match the client rules. */
    #[DataProvider('aneh')]
    public function test_nilai_aneh(string $masuk, ?string $galat, ?int $hasil): void
    {
        $spt = $this->spt();
        $t = $this->detail($spt)->call('buka', 'dana')->set('realisasi_dana', $masuk)->set('diinput_oleh', $this->penginput->id)->call('simpanDana');
        if ($galat) {
            $t->assertHasErrors(['realisasi_dana'])->assertSee($galat);
            $this->assertNull($spt->fresh()->realisasi_dana);
        } else {
            $t->assertHasNoErrors();
            $this->assertSame($hasil, $spt->fresh()->realisasi_dana);
        }
    }

    public static function aneh(): array
    {
        $format = 'Realisasi dana harus berupa angka Rupiah, tidak boleh minus.';

        return [
            'koma desimal' => ['Rp 1.000.000,50', null, 1000000],
            'eksponen' => ['1e9', $format, null],
            'minus' => ['-5', $format, null],
            'huruf' => ['12a', $format, null],
            'desimal inggris' => ['600000.00', $format, null],
            'spasi tengah' => [' 1 000 ', null, 1000],
            '13 digit' => ['1000000000000', 'Realisasi dana maksimal Rp999.999.999.999.', null],
            'batas' => ['999999999999', null, 999999999999],
        ];
    }

    /** Review Focus 4. */
    public function test_dibatalkan_toast_bukan_403(): void
    {
        $spt = $this->spt('dibatalkan');
        $this->detail($spt)->call('buka', 'dana')->assertDispatched('toast', type: 'error', message: UbahRealisasiDana::DIBATALKAN)->assertSet('modal', '');
        $this->gagal(fn () => $this->ubah($spt, '100'), 'spt', UbahRealisasiDana::DIBATALKAN);
        $this->assertNull($spt->fresh()->realisasi_dana);
    }

    public function test_tab_basi_setelah_dibatalkan_menjadi_toast_dan_modal_tetap_ada(): void
    {
        $spt = $this->spt();
        $t = $this->detail($spt)->call('buka', 'dana')->set('realisasi_dana', '700000')->set('diinput_oleh', $this->penginput->id);
        $spt->forceFill(['status_pengajuan' => StatusPengajuan::Dibatalkan])->save();
        $t->assertSet('modal', 'dana')->call('simpanDana')
            ->assertDispatched('toast', type: 'error', message: UbahRealisasiDana::DIBATALKAN)->assertDispatched('fokus-ke', id: 'judul-spt')
            ->assertSet('modal', '');
        $this->assertNull($spt->fresh()->realisasi_dana);
    }
}
