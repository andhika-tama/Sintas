<?php

namespace Tests\Feature\Keamanan;

use App\Actions\Spt\MintaRevisi;
use App\Actions\Spt\TolakPengajuan;
use App\Livewire\Admin;
use App\Livewire\BagianUmum\Pengajuan;
use App\Livewire\BagianUmum\Register;
use App\Livewire\Irban\Dasbor as IrbanDasbor;
use App\Livewire\Irban\Pkpt as IrbanPkpt;
use App\Livewire\Irban\SptForm;
use App\Livewire\Irban\SptSaya;
use App\Livewire\LogAktivitas;
use App\Livewire\Notifikasi\Daftar;
use App\Livewire\Notifikasi\Lonceng;
use App\Livewire\Pimpinan\Dasbor as PimpinanDasbor;
use App\Livewire\Pimpinan\Monitoring;
use App\Livewire\Pimpinan\PerubahanPkpt as PimpinanPerubahanPkpt;
use App\Livewire\Pimpinan\PerubahanPkptDetail;
use App\Livewire\Rekap;
use App\Livewire\Spt\Detail;
use App\Livewire\Spt\Komentar;
use App\Models\Ekspor as EksporModel;
use App\Models\Irban;
use App\Models\Komentar as KomentarModel;
use App\Models\Pkpt;
use App\Models\PkptPerubahan;
use App\Models\Spt;
use App\Models\User;
use App\Services\Ekspor;
use App\Services\Ekspor\PembangunRekap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 11 Task 1: every Livewire action re-authorizes for the *current* user. Each component is mounted by its rightful user, then the session
 * user is swapped (`$this->actingAs($lain)`) so the following call replays the payload as someone else, as the update endpoint would.
 */
class OtorisasiAksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pimpinan;

    private User $irban;

    private User $irbanLain;

    private User $bagianUmum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->irban = User::factory()->irban()->create(['irban_id' => Irban::factory()->create()->id]);
        $this->irbanLain = User::factory()->irban()->create(['irban_id' => Irban::factory()->create()->id]);
        $this->bagianUmum = User::factory()->bagianUmum()->create();
    }

    /** Mounts as `$pemilik`, then makes `$lain` the session user for every later request. */
    private function putar(User $pemilik, User $lain, string $kelas, array $param = [])
    {
        $c = Livewire::actingAs($pemilik)->test($kelas, $param);
        $this->actingAs($lain);

        return $c;
    }

    public function test_admin_master_data_ditolak_untuk_pimpinan(): void
    {
        $pkpt = Pkpt::factory()->create();
        $perubahan = PkptPerubahan::factory()->create();
        $irbanId = $this->irban->irban_id;
        $aksi = [
            [Admin\Dasbor::class, [], []],
            [Admin\Impor::class, [], [['impor']]],
            [Admin\Irban::class, [], [['save'], ['delete']]],
            [Admin\IrbanTahunan::class, [], [['save', $irbanId]]],
            [Admin\JenisPenugasan::class, [], [['save'], ['delete']]],
            [Admin\Klasifikasi::class, [], [['save']]],
            [Admin\Kontak::class, [], [['save']]],
            [Admin\LatarMasuk::class, [], [['save'], ['batal'], ['confirmHapus'], ['hapus']]],
            [Admin\Objek::class, [], [['save'], ['delete']]],
            [Admin\Pegawai::class, [], [['save'], ['delete'], ['toggleAktif', 1]]],
            [Admin\PegawaiImpor::class, [], [['impor']]],
            [Admin\Pengaturan::class, [], [['save', 'penugasan']]],
            [Admin\Pengguna::class, [], [['save'], ['aturUlang']]],
            [Admin\Pkpt::class, [], [['save'], ['delete']]],
            [Admin\PkptDetail::class, ['pkpt' => $pkpt], [['simpan'], ['delete'], ['sahkan'], ['buatPerubahan']]],
            [Admin\PkptImpor::class, ['pkpt' => $pkpt], [['impor']]],
            [Admin\Slide::class, [], [['save'], ['pindahkan', 1, 0], ['aturAktif', 1, false]]],
            [Admin\Tim::class, [], [['save'], ['delete']]],
            [Admin\WilayahIrban::class, [], [['save', $irbanId]]],
            [Admin\PerubahanPkpt::class, ['pkpt' => $perubahan->pkpt_id, 'perubahan' => $perubahan],
                [['simpanForm'], ['simpanOperasi'], ['simpanSk'], ['ajukan'], ['buang'], ['aturPengingat'], ['tampung', 1]]],
        ];

        foreach ($aksi as [$kelas, $param, $panggilan]) {
            Livewire::actingAs($this->pimpinan)->test($kelas, $param)->assertForbidden();
            // A bare re-render (no method call, e.g. a property update) must not return Admin data either.
            foreach ([['$refresh'], ...$panggilan] as $p) {
                $this->putar($this->admin, $this->pimpinan, $kelas, $param)->call(...$p)->assertForbidden();
            }
        }
    }

    public function test_bagian_umum_terima_tolak_ditolak_untuk_irban(): void
    {
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->irban_id]);
        foreach ([['terima'], ['bukaTerima'], ['kirimCatatan'], ['pilihSpt', $spt->id]] as $p) {
            $c = Livewire::actingAs($this->bagianUmum)->withQueryParams(['pilih' => $spt->id])->test(Pengajuan::class);
            $this->actingAs($this->irban);
            $c->call(...$p)->assertForbidden();
        }
        $sptDiterima = Spt::factory()->diterima()->create(['irban_id' => $this->irban->irban_id]);
        foreach ([['catatManual'], ['bukaBatal', $sptDiterima->id], ['batalkan'], ['aturAwal'], ['ekspor', 'xlsx']] as $p) {
            $this->putar($this->bagianUmum, $this->irban, Register::class)->call(...$p)->assertForbidden();
        }
        $this->assertSame('menunggu_nomor', $spt->fresh()->status_pengajuan->value);
    }

    /** Revisi/Tolak called directly (not through the component): the role is checked before the catatan, so a wrong role gets 403 even with an empty one. */
    public function test_revisi_dan_tolak_langsung_oleh_peran_salah_ditolak_sebelum_validasi_catatan(): void
    {
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->irban_id]);

        foreach ([MintaRevisi::class, TolakPengajuan::class] as $aksi) {
            foreach ([$this->irban, $this->pimpinan, $this->admin] as $salah) {
                foreach (['', '   ', 'Catatan yang cukup panjang.'] as $catatan) {
                    try {
                        app($aksi)->handle($salah, $spt, $catatan);
                        $this->fail("{$aksi} oleh {$salah->role->value} dengan catatan '{$catatan}' harus ditolak.");
                    } catch (AuthorizationException) {
                        $this->assertSame('menunggu_nomor', $spt->fresh()->status_pengajuan->value);
                    }
                }
            }
        }
        // Control: the rightful role with an empty catatan still gets the validation error.
        $this->expectException(ValidationException::class);
        app(TolakPengajuan::class)->handle($this->bagianUmum, $spt, '');
    }

    public function test_ekspor_rekap_irban_lain_tidak_bisa_lewat_livewire(): void
    {
        Queue::fake();
        // Force the queued path so the stored filter can be read back.
        $this->partialMock(PembangunRekap::class, fn ($m) => $m->shouldReceive('jumlah')->andReturn(Ekspor::BATAS_LANGSUNG + 1));

        $c = Livewire::actingAs($this->irban)->test(Rekap::class)->set('irban', (string) $this->irbanLain->irban_id)
            ->call('bukaEkspor', 'xlsx')->call('unduhEkspor');
        $c->assertSet('irban', (string) $this->irban->irban_id);
        $this->assertSame($this->irban->irban_id, EksporModel::latest('id')->first()->filter['irban']);

        // Control: Pimpinan may pick any irban, so the same call keeps the other irban.
        Livewire::actingAs($this->pimpinan)->test(Rekap::class)->set('irban', (string) $this->irbanLain->irban_id)
            ->call('bukaEkspor', 'xlsx')->call('unduhEkspor');
        $this->assertSame($this->irbanLain->irban_id, EksporModel::latest('id')->first()->filter['irban']);
        $this->assertSame(2, EksporModel::count());
    }

    public function test_spt_form_irban_lain_tidak_bisa_menyimpan_spt_milik_irban_lain(): void
    {
        $spt = Spt::factory()->create(['irban_id' => $this->irban->irban_id, 'dibuat_oleh' => $this->irban->id]);
        foreach (['simpan', 'ajukan'] as $m) {
            $this->putar($this->irban, $this->irbanLain, SptForm::class, ['spt' => $spt])->call($m)->assertNotFound();
            $this->putar($this->irban, $this->pimpinan, SptForm::class, ['spt' => $spt])->call($m)->assertForbidden();
        }
        // The id is #[Locked]: the client cannot point an own form at someone else's SPT.
        $sptLain = Spt::factory()->create(['irban_id' => $this->irbanLain->irban_id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->irban)->test(SptForm::class, ['spt' => $spt])->set('sptId', $sptLain->id);
    }

    public function test_detail_spt_aksi_pelaksanaan_ditolak_untuk_irban_lain_dan_peran_lain(): void
    {
        $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban->irban_id]);
        foreach ([['tandaiSelesai'], ['unggahFinal'], ['simpanTanggal'], ['simpanKendala'], ['buka', 'selesai'], ['hapusBerkas'], ['gantiPenginput']] as $p) {
            $this->putar($this->irban, $this->irbanLain, Detail::class, ['spt' => $spt])->call(...$p)->assertForbidden();
            $this->putar($this->irban, $this->bagianUmum, Detail::class, ['spt' => $spt])->call(...$p)->assertForbidden();
        }
        $this->assertNull($spt->fresh()->ditandai_selesai_pada);
    }

    public function test_komentar_tutup_ditolak_untuk_irban(): void
    {
        $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban->irban_id]);
        $root = KomentarModel::factory()->mintaPenjelasan()->create(['spt_id' => $spt->id, 'user_id' => $this->pimpinan->id]);
        $this->putar($this->pimpinan, $this->irban, Komentar::class, ['spt' => $spt])->call('tutup', $root->id)->assertForbidden();
        $this->putar($this->pimpinan, $this->irbanLain, Komentar::class, ['spt' => $spt])->call('kirimBalasan')->assertForbidden();
        $this->putar($this->irban, $this->admin, Komentar::class, ['spt' => $spt])->call('kirimKomentar')->assertForbidden();
        $this->assertSame('terbuka', $root->fresh()->status_permintaan->value);
    }

    public function test_putusan_perubahan_pkpt_ditolak_untuk_admin(): void
    {
        $p = PkptPerubahan::factory()->diajukan()->create();
        foreach (['setujui', 'tolak', '$refresh'] as $m) {
            $this->putar($this->pimpinan, $this->admin, PerubahanPkptDetail::class, ['perubahan' => $p])->call($m)->assertForbidden();
        }
        $this->putar($this->pimpinan, $this->admin, PimpinanPerubahanPkpt::class)->call('$refresh')->assertForbidden();
        $this->assertSame('diajukan', $p->fresh()->status->value);
    }

    public function test_pengguna_atur_ulang_ditolak_untuk_pimpinan(): void
    {
        $target = User::factory()->irban()->create();
        $c = $this->putar($this->admin, $this->admin, Admin\Pengguna::class)->call('confirmReset', $target->id);
        $this->actingAs($this->pimpinan);
        $c->call('aturUlang')->assertForbidden();
    }

    public function test_log_aktivitas_ditolak_untuk_irban(): void
    {
        foreach (['aturUlang', '$refresh'] as $m) {
            $this->putar($this->admin, $this->irban, LogAktivitas::class)->call($m)->assertForbidden();
        }
        Livewire::actingAs($this->irban)->test(LogAktivitas::class)->assertForbidden();
    }

    public function test_rekap_dan_monitoring_tidak_keluar_dari_cakupan(): void
    {
        // An Irban's rekap (screen and export filter) is always its own irban, whatever the client sends.
        $c = Livewire::actingAs($this->irban)->test(Rekap::class)->set('irban', (string) $this->irbanLain->irban_id);
        $c->assertSet('irban', (string) $this->irban->irban_id);
        $this->assertSame($this->irban->irban_id, app(PembangunRekap::class)->filter($this->irban, ['irban' => $this->irbanLain->irban_id])['irban']);

        foreach ([['bukaEkspor', 'xlsx'], ['unduhEkspor'], ['$refresh']] as $p) {
            $this->putar($this->irban, $this->bagianUmum, Rekap::class)->call(...$p)->assertForbidden();
        }
        foreach ([['ekspor', 'xlsx'], ['aturUlang'], ['$refresh']] as $p) {
            $this->putar($this->pimpinan, $this->irban, Monitoring::class)->call(...$p)->assertForbidden();
        }
        $this->putar($this->pimpinan, $this->irban, PimpinanDasbor::class)->call('$refresh')->assertForbidden();
    }

    public function test_halaman_irban_ditolak_untuk_peran_lain(): void
    {
        Pkpt::factory()->disahkan()->create(['tahun' => (int) now('Asia/Jakarta')->year]);
        foreach ([[IrbanPkpt::class, ['kirimPerubahan']], [IrbanPkpt::class, ['bukaPerubahan', 1]], [SptSaya::class, ['$refresh']], [IrbanDasbor::class, ['$refresh']]] as [$kelas, $p]) {
            $this->putar($this->irban, $this->pimpinan, $kelas)->call(...$p)->assertForbidden();
        }
    }

    public function test_notifikasi_pengguna_lain_tidak_tersentuh(): void
    {
        $milikLain = DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'uji', 'notifiable_type' => $this->irbanLain->getMorphClass(),
            'notifiable_id' => $this->irbanLain->id, 'data' => ['url' => '/spt/1'],
        ]);

        Livewire::actingAs($this->irban)->test(Daftar::class)
            ->call('tandaiDibaca', $milikLain->id)
            ->set('terpilih', [$milikLain->id])->call('tandaiTerpilih')
            ->call('buka', $milikLain->id)->assertNoRedirect();
        Livewire::actingAs($this->irban)->test(Lonceng::class)->call('buka', $milikLain->id)->assertNoRedirect();

        $this->assertNull($milikLain->fresh()->read_at);
    }
}
