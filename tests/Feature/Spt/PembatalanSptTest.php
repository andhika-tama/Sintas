<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\BatalkanNomor;
use App\Actions\Spt\TerimaPengajuan;
use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Livewire\BagianUmum\Register;
use App\Livewire\Spt\Detail;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Pegawai;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\EmailPegawai;
use App\Notifications\NomorDibatalkan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** versi 1.2.1 Task 4 (K4/K5): the owning Irban and Bagian Umum cancel a numbered SPT before it is Selesai; notifications per direction. */
class PembatalanSptTest extends TestCase
{
    use RefreshDatabase;

    private const SELESAI = 'SPT sudah Selesai; penugasan tidak dapat dibatalkan.';

    private const BELUM_TERBIT = 'Hanya nomor yang sudah terbit yang dapat dibatalkan.';

    private Irban $irban;

    private User $irbanUser;

    private User $irbanLain;

    private User $bu;

    private User $bu2;

    private User $pimpinan;

    private User $pimpinan2;

    private User $admin;

    private Pegawai $penginput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create(['nama' => 'Irban II', 'kode_nomor' => 'INSPEKTORAT-II']);
        $this->irbanUser = User::factory()->irban()->create(['irban_id' => $this->irban->id]);
        $this->irbanLain = User::factory()->irban()->create(['irban_id' => Irban::factory()->create()->id]);
        $this->bu = User::factory()->bagianUmum()->create();
        $this->bu2 = User::factory()->bagianUmum()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->pimpinan2 = User::factory()->pimpinan()->create();
        $this->admin = User::factory()->admin()->create();
        $this->penginput = Pegawai::factory()->create(['nama_bergelar' => 'Budi Penginput, S.E.']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
    }

    /** A real issued number (Penomoran), optionally with the pegawai who entered the submission. */
    private function terbit(?Pegawai $diinput = null): Spt
    {
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id, 'diinput_oleh' => $diinput?->id]);

        return app(TerimaPengajuan::class)->handle($this->bu, $spt);
    }

    private function batal(User $user, Spt $spt, ?int $diinput = null): Spt
    {
        return app(BatalkanNomor::class)->handle($user, $spt, 'Penugasan digabung ke SPT lain.', $diinput);
    }

    private function detail(Spt $spt, ?User $u = null)
    {
        return Livewire::actingAs($u ?? $this->irbanUser)->test(Detail::class, ['spt' => $spt]);
    }

    private function gagal(callable $fn, string $key, string $pesan): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertSame($pesan, $e->errors()[$key][0] ?? null);
        }
    }

    public function test_irban_pemilik_membatalkan_spt_bernomor(): void
    {
        $spt = $this->terbit();
        $aksi = app(BatalkanNomor::class);

        // Irban must name the penginput; the alasan rule is the existing one.
        $this->gagal(fn () => $aksi->handle($this->irbanUser, $spt, 'Penugasan digabung.'), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->gagal(fn () => $aksi->handle($this->irbanUser, $spt, 'pendek', $this->penginput->id), 'alasan', 'Alasan pembatalan minimal 10 karakter.');
        $this->assertSame(StatusPengajuan::Diterima, $spt->fresh()->status_pengajuan);

        $hasil = $this->batal($this->irbanUser, $spt, $this->penginput->id);

        $this->assertSame(StatusPengajuan::Dibatalkan, $hasil->status_pengajuan);
        $r = $spt->riwayat()->where('kejadian', KejadianSpt::DIBATALKAN)->sole();
        $this->assertSame([$this->irbanUser->id, $this->penginput->id, 'irban', 'Penugasan digabung ke SPT lain.'], [$r->oleh, $r->diinput_oleh, $r->nilai_baru['peran'], $r->alasan]);
        $this->assertSame($this->penginput->id, session("diinput_oleh.{$this->irban->id}"));

        // Detail Riwayat names the role, the account and the penginput.
        $this->detail($spt->fresh())->assertSee('Nomor dibatalkan')
            ->assertSeeInOrder(['Nomor dibatalkan', 'Irban · '.$this->irbanUser->name, '· Diinput oleh Budi Penginput, S.E.', 'Penugasan digabung ke SPT lain.']);

        // Bagian Umum: role recorded, no penginput.
        $lain = $this->terbit();
        $this->batal($this->bu, $lain);
        $r = $lain->riwayat()->where('kejadian', KejadianSpt::DIBATALKAN)->sole();
        $this->assertSame([$this->bu->id, null, 'bagian_umum'], [$r->oleh, $r->diinput_oleh, $r->nilai_baru['peran']]);
        $this->detail($lain->fresh(), $this->bu)->assertSee('Bagian Umum · '.$this->bu->name);
    }

    public function test_irban_pemilik_lewat_detail(): void
    {
        $spt = $this->terbit();
        $this->detail($spt)->assertSee('Batalkan Penugasan')
            ->call('buka', 'batal')->assertSet('modal', 'batal')->assertDispatched('open-modal', name: 'aksi-batal')
            ->assertSee('Nomor tidak dapat dipakai ulang.')->assertSee('Alasan pembatalan')->assertSee('Kembali')
            ->set('alasan', 'pendek')->call('batalkan')->assertHasErrors(['alasan', 'diinput_oleh'])->assertNotDispatched('toast')
            ->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id)->call('batalkan')
            ->assertHasNoErrors()->assertSet('modal', '')->assertDispatched('close-modal', name: 'aksi-batal')
            ->assertDispatched('toast', type: 'success', message: 'Penugasan dibatalkan')->assertDispatched('fokus-ke', id: 'judul-spt')
            ->assertDontSee('Batalkan Penugasan');
        $this->assertSame(StatusPengajuan::Dibatalkan, $spt->fresh()->status_pengajuan);

        // Bagian Umum and Pimpinan get no button on the detail page; Pimpinan cannot call it.
        $lain = $this->terbit();
        $this->detail($lain, $this->bu)->assertDontSee('Batalkan Penugasan');
        $this->detail($lain, $this->pimpinan)->assertDontSee('Batalkan Penugasan')->call('buka', 'batal')->assertForbidden();
        $this->detail($lain, $this->pimpinan)->set('alasan', 'Penugasan digabung ke SPT lain.')->call('batalkan')->assertForbidden();
        $this->assertSame(StatusPengajuan::Diterima, $lain->fresh()->status_pengajuan);
    }

    public function test_irban_lain_ditolak(): void
    {
        $spt = $this->terbit();

        try {
            $this->batal($this->irbanLain, $spt, $this->penginput->id);
            $this->fail('AuthorizationException expected');
        } catch (AuthorizationException) {
        }
        foreach ([$this->pimpinan, $this->admin] as $u) {
            $this->assertThrows(fn () => $this->batal($u, $spt), AuthorizationException::class);
        }

        // Livewire endpoint: a snapshot mounted by the owner, replayed by another Irban.
        $c = $this->detail($spt)->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id);
        $this->actingAs($this->irbanLain);
        $c->call('batalkan')->assertForbidden();
        $c = $this->detail($spt);
        $this->actingAs($this->irbanLain);
        $c->call('buka', 'batal')->assertForbidden();

        $this->assertSame(StatusPengajuan::Diterima, $spt->fresh()->status_pengajuan);
    }

    public function test_selesai_tidak_bisa_dibatalkan(): void
    {
        $spt = $this->terbit();
        $spt->forceFill(['ditandai_selesai_pada' => now()])->save();

        $this->assertSame(self::SELESAI, app(BatalkanNomor::class)->cegah($spt));
        $this->assertNull(app(BatalkanNomor::class)->cegah($this->terbit()));
        $this->assertSame(self::BELUM_TERBIT, app(BatalkanNomor::class)->cegah(Spt::factory()->menungguNomor()->create()));
        $this->gagal(fn () => $this->batal($this->irbanUser, $spt, $this->penginput->id), 'spt', self::SELESAI);
        $this->gagal(fn () => $this->batal($this->bu, $spt), 'spt', self::SELESAI);
        $this->assertSame(StatusPengajuan::Diterima, $spt->fresh()->status_pengajuan);

        // The Irban button stays visible, disabled, with the reason written next to it.
        $this->detail($spt)->assertSee(self::SELESAI)->assertSeeHtml('aria-describedby="batal-blokir"')->assertSeeHtml('id="batal-blokir"')
            ->call('buka', 'batal')->assertSet('modal', '')->assertDispatched('toast', type: 'error', message: self::SELESAI)
            ->assertDispatched('fokus-ke', id: 'judul-spt');

        // Register: disabled row button with the reason; opening it is a toast.
        Livewire::actingAs($this->bu)->test(Register::class)
            ->assertSee(self::SELESAI)->assertSeeHtml('aria-describedby="batal-blokir-'.$spt->id.'"')
            ->call('bukaBatal', $spt->id)->assertSet('showBatal', false)->assertDispatched('toast', type: 'error', message: self::SELESAI);
    }

    /** Review Focus 3: the modal was opened, then the SPT was marked Selesai in another tab. */
    public function test_selesai_di_tab_lain_saat_modal_terbuka_menjadi_toast(): void
    {
        $spt = $this->terbit();
        $c = $this->detail($spt)->call('buka', 'batal')->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id);
        $spt->forceFill(['ditandai_selesai_pada' => now()])->save();

        $c->call('batalkan')->assertOk()->assertSet('modal', '')->assertDispatched('close-modal', name: 'aksi-batal')
            ->assertDispatched('toast', type: 'error', message: self::SELESAI)->assertDispatched('fokus-ke', id: 'judul-spt');
        $this->assertSame(StatusPengajuan::Diterima, $spt->fresh()->status_pengajuan);
    }

    /** Review Focus 2: two tabs cancel the same SPT; the second gets the shared guard's toast, not 403/500. */
    public function test_pembatalan_kedua_di_tab_basi_menjadi_toast(): void
    {
        // Irban tab, after Bagian Umum cancelled.
        $spt = $this->terbit();
        $c = $this->detail($spt)->call('buka', 'batal')->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id);
        $this->batal($this->bu, $spt);
        $c->call('batalkan')->assertOk()->assertDispatched('toast', type: 'error', message: self::BELUM_TERBIT)->assertDispatched('fokus-ke', id: 'judul-spt');

        // Two Irban tabs.
        $spt = $this->terbit();
        $a = $this->detail($spt)->call('buka', 'batal')->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id);
        $b = $this->detail($spt)->call('buka', 'batal')->set('alasan', 'Penugasan digabung ke SPT lain.')->set('diinput_oleh', $this->penginput->id);
        $a->call('batalkan')->assertDispatched('toast', type: 'success', message: 'Penugasan dibatalkan');
        $b->call('batalkan')->assertOk()->assertDispatched('toast', type: 'error', message: self::BELUM_TERBIT);

        // Register tab, after the Irban cancelled.
        $spt = $this->terbit();
        $r = Livewire::actingAs($this->bu)->test(Register::class)->call('bukaBatal', $spt->id)->assertSet('showBatal', true)->set('alasan', 'Penugasan digabung ke SPT lain.');
        $this->batal($this->irbanUser, $spt, $this->penginput->id);
        $r->call('batalkan')->assertOk()->assertHasNoErrors()->assertSet('showBatal', false)
            ->assertDispatched('toast', type: 'error', message: self::BELUM_TERBIT)->assertDispatched('fokus-ke', id: 'judul-register');
        $this->assertSame(1, $spt->riwayat()->where('kejadian', KejadianSpt::DIBATALKAN)->count());
    }

    public function test_notifikasi_pembatalan_oleh_bagian_umum(): void
    {
        $pegawai = Pegawai::factory()->create(['email' => 'pegawai@example.test']);
        $spt = $this->terbit($pegawai);
        Notification::fake();

        $this->batal($this->bu, $spt);

        foreach ([$this->irbanUser, $this->pimpinan, $this->pimpinan2] as $u) {
            Notification::assertSentTo($u, NomorDibatalkan::class);
        }
        foreach ([$this->bu, $this->bu2, $this->admin, $this->irbanLain] as $u) {
            Notification::assertNotSentTo($u, NomorDibatalkan::class);
        }
        Notification::assertSentOnDemand(EmailPegawai::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'pegawai@example.test');

        $n = Notification::sent($this->pimpinan, NomorDibatalkan::class)->first();
        $this->assertSame(["Nomor SPT {$spt->nomor_lengkap} dibatalkan oleh Bagian Umum."], $n->toMail($this->pimpinan)->introLines);
        // The bell (no email needed) names the number, the canceller and the reason too.
        $bel = $n->toArray($this->pimpinan);
        $this->assertSame(["SPT {$spt->nomor_lengkap} · oleh Bagian Umum", 'Penugasan digabung ke SPT lain.'], [$bel['konteks'], $bel['catatan']]);
        $this->assertSame($bel, Notification::sent($this->irbanUser, NomorDibatalkan::class)->first()->toArray($this->irbanUser));
    }

    public function test_notifikasi_pembatalan_oleh_irban(): void
    {
        $pegawai = Pegawai::factory()->create(['email' => 'pegawai@example.test']);
        $spt = $this->terbit($pegawai);
        Notification::fake();

        $this->batal($this->irbanUser, $spt, $this->penginput->id);

        foreach ([$this->bu, $this->bu2, $this->pimpinan, $this->pimpinan2] as $u) {
            Notification::assertSentTo($u, NomorDibatalkan::class, fn ($n, $channels) => true);
            $this->assertCount(1, Notification::sent($u, NomorDibatalkan::class));
        }
        foreach ([$this->irbanUser, $this->irbanLain, $this->admin] as $u) {
            Notification::assertNotSentTo($u, NomorDibatalkan::class);
        }
        $this->assertArrayNotHasKey(AnonymousNotifiable::class, Notification::sentNotifications());

        $n = Notification::sent($this->bu, NomorDibatalkan::class)->first();
        $html = (string) $n->toMail($this->bu)->render();
        $this->assertStringContainsString("Nomor SPT {$spt->nomor_lengkap} dibatalkan oleh Irban II (diinput oleh Budi Penginput, S.E.).", strip_tags($html));
        $this->assertStringContainsString('Catatan: Penugasan digabung ke SPT lain.', strip_tags($html));
        $this->assertSame([
            'jenis' => 'nomor_dibatalkan', 'judul' => 'Nomor SPT dibatalkan', 'konteks' => "SPT {$spt->nomor_lengkap} · oleh Irban II (diinput oleh Budi Penginput, S.E.)",
            'url' => '/spt/'.$spt->id, 'catatan' => 'Penugasan digabung ke SPT lain.',
        ], $n->toArray($this->bu));
    }

    public function test_nomor_tidak_dipakai_ulang(): void
    {
        $n = $this->terbit();
        $this->batal($this->irbanUser, $n, $this->penginput->id);

        $this->assertSame($n->nomor_urut + 1, $this->terbit()->nomor_urut);
    }
}
