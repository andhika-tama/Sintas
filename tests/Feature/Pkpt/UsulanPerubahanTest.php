<?php

namespace Tests\Feature\Pkpt;

use App\Actions\Pkpt\Perubahan\AjukanPerubahan;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\Perubahan\HapusOperasi;
use App\Actions\Pkpt\Perubahan\PutuskanPerubahan;
use App\Actions\Pkpt\Perubahan\SimpanOperasi;
use App\Actions\Pkpt\Usulan\AjukanUsulan;
use App\Actions\Pkpt\Usulan\TampungUsulan;
use App\Actions\Pkpt\Usulan\TutupUsulan;
use App\Enums\AksiPerubahan;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusUsulan;
use App\Livewire\Admin\PerubahanPkpt as HalamanPerubahan;
use App\Livewire\Irban\Pkpt as HalamanIrban;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptUsulan;
use App\Models\User;
use App\Notifications\EmailPegawai;
use App\Notifications\UsulanPerubahanBaru;
use App\Notifications\UsulanPerubahanDiputuskan;
use App\Services\PerubahanPkpt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Fase 9.2 Task 9: Usulan Perubahan by Irban → Admin tampung/tutup → Pimpinan approval → diterapkan, notifications, scope. */
class UsulanPerubahanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pimpinan;

    private User $irban;

    private User $irbanLain;

    private Irban $irban1;

    private Irban $irban2;

    private Pegawai $penginput;

    private Pkpt $pkpt;

    private JenisPenugasan $jenis;

    private PkptItem $a;

    private PkptItem $b;

    private PkptItem $c;

    private PkptItem $milikLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 3, 25));
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->irban1 = Irban::factory()->create(['nama' => 'Irban I']);
        $this->irban2 = Irban::factory()->create(['nama' => 'Irban II']);
        $this->irban = User::factory()->irban()->create(['irban_id' => $this->irban1->id, 'email' => 'akun1@example.test']);
        $this->irbanLain = User::factory()->irban()->create(['irban_id' => $this->irban2->id]);
        $this->penginput = Pegawai::factory()->create(['email' => 'penginput@example.test']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban1->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
        $this->pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $this->jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu']);
        $item = fn (Irban $i, string $mulai, string $selesai) => PkptItem::factory()->disahkanDari()->create([
            'pkpt_id' => $this->pkpt->id, 'irban_id' => $i->id, 'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]);
        $this->a = $item($this->irban1, '2026-03-02', '2026-03-20');
        $this->b = $item($this->irban1, '2026-05-04', '2026-05-15');
        $this->c = $item($this->irban1, '2026-07-06', '2026-07-17');
        $this->milikLain = $item($this->irban2, '2026-08-03', '2026-08-14');
    }

    private function ajukan(?PkptItem $item, array $data, ?User $user = null): PkptUsulan
    {
        return app(AjukanUsulan::class)->handle($user ?? $this->irban, $this->pkpt, $item,
            $data + ['alasan' => 'Menyesuaikan jadwal OPD.', 'diinput_oleh' => $this->penginput->id]);
    }

    private function paket(): PkptPerubahan
    {
        return app(BuatPerubahan::class)->handle($this->admin, $this->pkpt, ['jenis' => 'perubahan', 'alasan' => 'Menampung usulan Irban.']);
    }

    private function galat(callable $fn, string $key, ?string $pesan = null): void
    {
        try {
            $fn();
            $this->fail("Expected a validation error on {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            if ($pesan) {
                $this->assertSame($pesan, $e->errors()[$key][0]);
            }
        }
    }

    private function emailKe(string $email): int
    {
        $n = 0;
        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $perKelas) {
            foreach ($perKelas[EmailPegawai::class] ?? [] as $kirim) {
                $n += ($kirim['notifiable']->routes['mail'] ?? null) === $email ? 1 : 0;
            }
        }

        return $n;
    }

    public function test_full_flow_ajukan_tampung_setujui_diterapkan_and_notifications(): void
    {
        $geser = $this->ajukan($this->a, ['aksi_usulan' => 'geser', 'tanggal_mulai' => '2026-03-09', 'tanggal_selesai' => '2026-03-27']);
        $hapus = $this->ajukan($this->b, ['aksi_usulan' => 'hapus']);
        $tambah = $this->ajukan(null, ['aksi_usulan' => 'tambah', 'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => '2026-10-05', 'tanggal_selesai' => '2026-10-16']);
        $lainnya = $this->ajukan($this->c, ['aksi_usulan' => 'lainnya', 'alasan' => 'Target SPT perlu jadi 2.']);
        Notification::assertSentTo($this->admin, UsulanPerubahanBaru::class);
        Notification::assertSentToTimes($this->admin, UsulanPerubahanBaru::class, 4);
        $this->assertSame(['terbuka'], PkptUsulan::pluck('status')->map->value->unique()->values()->all());
        $this->assertSame($this->irban1->id, $tambah->irban_id);
        $this->assertNull($tambah->pkpt_item_id);

        $p = $this->paket();
        $ops = collect([$geser, $hapus, $tambah, $lainnya])->map(fn ($u) => app(TampungUsulan::class)->handle($this->admin, $u, $p));
        [$opGeser, $opHapus, $opTambah, $opLainnya] = $ops->all();
        $this->assertSame([AksiPerubahan::Ubah, AksiPerubahan::Hapus, AksiPerubahan::Tambah, AksiPerubahan::Ubah], $ops->pluck('aksi')->all());
        $this->assertSame(['2026-03-09', '2026-03-27'], [$opGeser->data_baru['tanggal_mulai'], $opGeser->data_baru['tanggal_selesai']]);
        $this->assertSame($this->b->id, $opHapus->pkpt_item_id);
        $this->assertSame([$this->irban1->id, $this->jenis->id, '2026-10-05'], [$opTambah->data_baru['irban_id'], $opTambah->data_baru['jenis_penugasan_id'], $opTambah->data_baru['tanggal_mulai']]);
        $this->assertSame(PerubahanPkpt::data($this->c), $opLainnya->data_baru);
        foreach ([$geser, $hapus, $tambah, $lainnya] as $i => $u) {
            $u->refresh();
            $this->assertSame(StatusUsulan::Ditampung, $u->status);
            $this->assertSame($p->id, $u->perubahan_id);
            $this->assertSame($u->id, $ops[$i]->fresh()->usulan_id);
        }
        // The `lainnya` placeholder blocks submission until Admin edits it.
        $this->assertTrue(collect(app(PerubahanPkpt::class)->cek($p))->contains(fn ($m) => str_contains($m, PerubahanPkpt::TIDAK_BERUBAH)));
        app(SimpanOperasi::class)->handle($this->admin, $p, $opLainnya, ['aksi' => 'ubah', 'pkpt_item_id' => $this->c->id, 'target_spt' => 2]);

        app(AjukanPerubahan::class)->handle($this->admin, $p->fresh());
        app(PutuskanPerubahan::class)->handle($this->pimpinan, $p->fresh(), true, null);

        foreach ([$geser, $hapus, $tambah, $lainnya] as $u) {
            $this->assertSame(StatusUsulan::Diterapkan, $u->fresh()->status);
        }
        $this->assertSame('2026-03-09', $this->a->fresh()->tanggal_mulai->toDateString());
        $this->assertSame(StatusItemPkpt::Dihapus, $this->b->fresh()->status);
        $this->assertSame(2, $this->c->fresh()->target_spt);
        $this->assertSame($p->id, PkptItem::whereDate('tanggal_mulai', '2026-10-05')->value('ditambahkan_perubahan_id'));

        Notification::assertSentToTimes($this->irban, UsulanPerubahanDiputuskan::class, 4);
        Notification::assertSentTo($this->irban, UsulanPerubahanDiputuskan::class,
            fn ($n) => $n->toArray($this->irban)['jenis'] === 'usulan_perubahan_diterapkan' && $n->toArray($this->irban)['url'] === '/irban/pkpt?tahun=2026&tampilan=usulan');
        Notification::assertNotSentTo($this->irbanLain, UsulanPerubahanDiputuskan::class);
        $this->assertSame(4, $this->emailKe('penginput@example.test'));
    }

    public function test_tutup_requires_catatan_then_closes_and_notifies(): void
    {
        $u = $this->ajukan($this->a, ['aksi_usulan' => 'hapus']);
        $this->galat(fn () => app(TutupUsulan::class)->handle($this->admin, $u, '  '), 'catatan_penutupan', 'Catatan penutupan wajib diisi.');
        $this->galat(fn () => app(TutupUsulan::class)->handle($this->admin, $u, 'Pendek'), 'catatan_penutupan', 'Catatan penutupan minimal 10 karakter.');
        Notification::assertNothingSentTo($this->irban);

        app(TutupUsulan::class)->handle($this->admin, $u, 'Item masih diperlukan tahun ini.');
        $u->refresh();
        $this->assertSame(StatusUsulan::Ditutup, $u->status);
        $this->assertSame($this->admin->id, $u->ditutup_oleh);
        $this->assertSame('Item masih diperlukan tahun ini.', $u->catatan_penutupan);
        Notification::assertSentTo($this->irban, UsulanPerubahanDiputuskan::class,
            fn ($n) => $n->toArray($this->irban)['jenis'] === 'usulan_perubahan_ditutup' && $n->toArray($this->irban)['catatan'] === 'Item masih diperlukan tahun ini.');
        $this->assertSame(1, $this->emailKe('penginput@example.test'));

        // Closed usulan can be neither closed again nor taken into a package.
        $this->galat(fn () => app(TutupUsulan::class)->handle($this->admin, $u, 'Ditutup sekali lagi.'), 'usulan');
        $this->galat(fn () => app(TampungUsulan::class)->handle($this->admin, $u, $this->paket()), 'usulan');
    }

    public function test_pegawai_email_skipped_when_equal_to_irban_account_or_inactive(): void
    {
        $sama = $this->ajukan($this->a, ['aksi_usulan' => 'hapus']);
        $this->irban->forceFill(['email' => 'PENGINPUT@example.test'])->save();
        app(TutupUsulan::class)->handle($this->admin, $sama, 'Email sama dengan akun.');
        $this->assertSame(0, $this->emailKe('penginput@example.test'));
        Notification::assertSentTo($this->irban, UsulanPerubahanDiputuskan::class);

        $this->irban->forceFill(['email' => 'akun1@example.test'])->save();
        $nonaktif = $this->ajukan($this->b, ['aksi_usulan' => 'hapus']);
        $this->penginput->forceFill(['aktif' => false])->save();
        app(TutupUsulan::class)->handle($this->admin, $nonaktif, 'Pegawai sudah nonaktif.');
        $this->assertSame(0, $this->emailKe('penginput@example.test'));
    }

    public function test_operasi_removed_returns_usulan_to_terbuka(): void
    {
        $u = $this->ajukan($this->a, ['aksi_usulan' => 'geser', 'tanggal_mulai' => '2026-03-09', 'tanggal_selesai' => '2026-03-27']);
        $p = $this->paket();
        $op = app(TampungUsulan::class)->handle($this->admin, $u, $p);
        app(HapusOperasi::class)->handle($this->admin, $op);
        $u->refresh();
        $this->assertSame(StatusUsulan::Terbuka, $u->status);
        $this->assertNull($u->perubahan_id);
    }

    public function test_validation_rules_of_ajukan(): void
    {
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'pindah']), 'aksi_usulan', 'Pilih jenis usulan.');
        $this->galat(fn () => $this->ajukan(null, ['aksi_usulan' => 'geser']), 'pkpt_item_id');
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'geser', 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']),
            'tanggal_mulai', 'Jadwal baru sama dengan jadwal yang berlaku.');
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'geser', 'tanggal_mulai' => '2027-01-04', 'tanggal_selesai' => '2027-01-08']), 'tanggal_mulai');
        $this->galat(fn () => $this->ajukan(null, ['aksi_usulan' => 'tambah', 'tanggal_mulai' => '2026-10-05', 'tanggal_selesai' => '2026-10-16']), 'jenis_penugasan_id');
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'hapus', 'alasan' => '  ']), 'alasan', 'Isi alasan usulan.');
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'hapus', 'alasan' => str_repeat('a', 1001)]), 'alasan');
        // "Diinput oleh" is required and must be a member of this irban for the current year.
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'hapus', 'diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->galat(fn () => $this->ajukan($this->a, ['aksi_usulan' => 'hapus', 'diinput_oleh' => Pegawai::factory()->create()->id]),
            'diinput_oleh', 'Penginput harus PPJ atau anggota aktif Irban ini tahun 2026.');
        $this->assertSame(0, PkptUsulan::count());

        $draf = Pkpt::factory()->create(['tahun' => 2031]);
        $this->galat(fn () => app(AjukanUsulan::class)->handle($this->irban, $draf, null, ['aksi_usulan' => 'tambah']), 'pkpt');
    }

    public function test_scope_irban_cannot_propose_for_another_irbans_item(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->ajukan($this->milikLain, ['aksi_usulan' => 'hapus']);
    }

    /** Stale tab: an item that was this irban's but was removed or moved by an approved Perubahan PKPT → toast + h1 focus, not a 404. */
    public function test_stale_item_removed_or_moved_shows_toast_not_404(): void
    {
        $p = PkptPerubahan::factory()->disetujui(1)->create(['pkpt_id' => $this->pkpt->id]);
        $p->operasi()->create(['aksi' => 'ubah', 'pkpt_item_id' => $this->c->id, 'urutan' => 1,
            'data_lama' => PerubahanPkpt::data($this->c), 'data_baru' => ['irban_id' => $this->irban2->id] + PerubahanPkpt::data($this->c)]);

        // 1.2 Task 7: the Irban page no longer opens usulan; the action keeps its stale-item rule until Task 8 removes it.
        $this->b->update(['status' => StatusItemPkpt::Dihapus]);
        $this->c->update(['irban_id' => $this->irban2->id]);
        $this->galat(fn () => $this->ajukan($this->c, ['aksi_usulan' => 'hapus']), 'item', AjukanUsulan::ITEM_BASI);
        $this->galat(fn () => $this->ajukan($this->b, ['aksi_usulan' => 'hapus']), 'item', AjukanUsulan::ITEM_BASI);
        $this->assertSame(0, PkptUsulan::count());

        // Never this irban's (even when dihapus or moved between other irbans): still a 404.
        $this->milikLain->update(['status' => StatusItemPkpt::Dihapus]);
        $this->expectException(NotFoundHttpException::class);
        $this->ajukan($this->milikLain, ['aksi_usulan' => 'hapus']);
    }

    public function test_scope_in_the_irban_page_and_non_irban_cannot_propose(): void
    {
        Livewire::actingAs($this->admin)->test(HalamanIrban::class)->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(AjukanUsulan::class)->handle($this->admin, $this->pkpt, null, ['aksi_usulan' => 'tambah']);
    }

    public function test_admin_panel_tampung_and_tutup_and_only_admin_may(): void
    {
        $geser = $this->ajukan($this->a, ['aksi_usulan' => 'geser', 'tanggal_mulai' => '2026-03-09', 'tanggal_selesai' => '2026-03-27', 'alasan' => 'Usulan geser panel']);
        $lainnya = $this->ajukan($this->c, ['aksi_usulan' => 'lainnya', 'alasan' => 'Usulan lainnya panel']);
        $tutup = $this->ajukan($this->b, ['aksi_usulan' => 'hapus', 'alasan' => 'Usulan hapus panel']);
        $p = $this->paket();
        $halaman = fn (?User $u = null) => Livewire::actingAs($u ?? $this->admin)->test(HalamanPerubahan::class, ['pkpt' => $this->pkpt, 'perubahan' => $p]);

        $halaman()->assertSee('Usulan dari Irban')->assertSee('Usulan geser panel')->assertSee('Tampung ke perubahan')->assertSee('Diinput oleh')
            ->call('tampung', $geser->id)->assertDispatched('fokus-ke', id: 'judul-usulan')->assertDontSee('Usulan geser panel')
            ->call('tampung', $lainnya->id)->assertSet('opOpen', true)->assertSet('aksi', 'ubah')
            ->call('bukaTutup', $tutup->id)->assertSet('tutupOpen', true)
            ->call('tutupUsulan')->assertHasErrors('catatan_penutupan')->assertSet('tutupOpen', true)
            ->set('catatan_penutupan', 'Item masih diperlukan.')->call('tutupUsulan')->assertHasNoErrors()->assertSet('tutupOpen', false)
            ->assertSee('Tidak ada usulan terbuka dari Irban.');
        $this->assertSame(StatusUsulan::Ditampung, $geser->fresh()->status);
        $this->assertSame(StatusUsulan::Ditutup, $tutup->fresh()->status);

        // Irban (or Pimpinan) calling the Admin component's actions directly is forbidden; the actions refuse too.
        $baru = $this->ajukan($this->a, ['aksi_usulan' => 'hapus']);
        $h = $halaman();
        $this->actingAs($this->irban);
        $h->call('tampung', $baru->id)->assertForbidden();
        $this->assertSame(StatusUsulan::Terbuka, $baru->fresh()->status);
        $h = $halaman()->call('bukaTutup', $baru->id)->set('catatan_penutupan', 'Ditutup lewat panggilan langsung.');
        $this->actingAs($this->irban);
        $h->call('tutupUsulan')->assertForbidden();
        $baru->refresh();
        $this->assertSame(StatusUsulan::Terbuka, $baru->status);
        $this->assertNull($baru->catatan_penutupan);
        $this->actingAs($this->admin);
        $halaman($this->irban)->assertForbidden();
        foreach ([fn () => app(TampungUsulan::class)->handle($this->irban, $baru, $p), fn () => app(TutupUsulan::class)->handle($this->pimpinan, $baru, 'Ditutup oleh pimpinan.')] as $fn) {
            try {
                $fn();
                $this->fail('Expected AuthorizationException.');
            } catch (AuthorizationException) {
                $this->assertSame(StatusUsulan::Terbuka, $baru->fresh()->status);
            }
        }
    }

    public function test_admin_cannot_tampung_another_pkpts_usulan_and_badge_counts_open(): void
    {
        $lain = PkptUsulan::factory()->create(['pkpt_item_id' => PkptItem::factory()->create(['pkpt_id' => Pkpt::factory()->disahkan()->create(['tahun' => 2030])->id])->id]);
        $p = $this->paket();
        Livewire::actingAs($this->admin)->test(HalamanPerubahan::class, ['pkpt' => $this->pkpt, 'perubahan' => $p])->call('tampung', $lain->id)->assertNotFound();

        $this->ajukan($this->a, ['aksi_usulan' => 'hapus']);
        $this->ajukan($this->b, ['aksi_usulan' => 'hapus']);
        $this->actingAs($this->admin)->get(route('admin.pkpt.show', $this->pkpt))->assertOk()->assertSee('2 usulan dari Irban terbuka');
    }

    /** Fase 11.2 Task 6 butir 17: a usulan closed in another tab opens no "Tutup usulan" modal; a toast and the h1 instead. */
    public function test_buka_tutup_usulan_yang_sudah_tidak_terbuka_memberi_toast(): void
    {
        $u = $this->ajukan($this->b, ['aksi_usulan' => 'hapus', 'alasan' => 'Usulan yang ditutup di tab lain']);
        $p = $this->paket();
        $h = Livewire::actingAs($this->admin)->test(HalamanPerubahan::class, ['pkpt' => $this->pkpt, 'perubahan' => $p]);
        app(TutupUsulan::class)->handle($this->admin, $u, 'Ditutup di tab lain.');
        $h->call('bukaTutup', $u->id)->assertSet('tutupOpen', false)->assertSet('tutupId', null)
            ->assertDispatched('toast', type: 'error', message: TampungUsulan::TIDAK_TERBUKA)->assertDispatched('fokus-ke', id: 'judul-perubahan');

        $terbuka = $this->ajukan($this->a, ['aksi_usulan' => 'hapus']); // control
        $h->call('bukaTutup', $terbuka->id)->assertSet('tutupOpen', true)->assertSet('tutupId', $terbuka->id);
    }
}
