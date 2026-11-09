<?php

namespace Tests\Feature\Log;

use App\Actions\MasterData\SaveTim;
use App\Actions\Pengguna\AturUlangKataSandi;
use App\Actions\Pkpt\SahkanPkpt;
use App\Actions\Profil\GantiKataSandi;
use App\Actions\Spt\SimpanSpt;
use App\Livewire\Admin\Pegawai as PegawaiPage;
use App\Models\Aktivitas;
use App\Models\Concerns\DicatatAktivitas;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\IrbanWilayah;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\Komentar;
use App\Models\LandingSlide;
use App\Models\NomorCounter;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use App\Models\Spt;
use App\Models\SptDokumen;
use App\Models\SptKendala;
use App\Models\SptPersonel;
use App\Models\Tim;
use App\Models\TimAnggota;
use App\Models\User;
use App\Services\Settings;
use App\Support\LabelAktivitas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class LogAktivitasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_model_penting_memakai_trait(): void
    {
        foreach ([User::class, Pegawai::class, Irban::class, IrbanTahunan::class, Tim::class, TimAnggota::class, Objek::class,
            IrbanWilayah::class, JenisPenugasan::class, Klasifikasi::class, Pkpt::class, PkptItem::class, PkptPerubahan::class,
            PkptPerubahanItem::class, PerubahanPenugasan::class, Spt::class, SptPersonel::class, SptDokumen::class, SptKendala::class,
            Komentar::class, LandingSlide::class, NomorCounter::class] as $kelas) {
            $this->assertContains(DicatatAktivitas::class, class_uses_recursive($kelas), $kelas);
        }
    }

    public function test_ubah_pegawai_mencatat_lama_dan_baru(): void
    {
        $admin = User::factory()->admin()->create();
        $p = Pegawai::factory()->create(['jabatan' => 'Auditor Muda']);
        $sebelum = Aktivitas::max('id');

        Livewire::actingAs($admin)->test(PegawaiPage::class)->call('edit', $p->id)
            ->set('jabatan', 'Auditor Madya')->call('save')->assertHasNoErrors();

        $baris = Aktivitas::where('id', '>', $sebelum)->get();
        $this->assertCount(1, $baris);
        $a = $baris->first();
        $this->assertSame('updated', $a->event);
        $this->assertSame($admin->id, $a->causer_id);
        $this->assertSame(['jabatan' => 'Auditor Muda'], $a->properties['old']);
        $this->assertSame(['jabatan' => 'Auditor Madya'], $a->properties['attributes']);
        $this->assertSame('127.0.0.1', $a->ip);
    }

    public function test_simpan_tanpa_perubahan_tidak_mencatat(): void
    {
        $p = Pegawai::factory()->create();
        $jumlah = Aktivitas::count();

        $p->save();
        $p->fresh()->save();

        $this->assertSame($jumlah, Aktivitas::count());
    }

    public function test_kata_sandi_tidak_pernah_masuk_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->pimpinan()->create();
        app(AturUlangKataSandi::class)->handle($admin, $user, 'SementaraKuat123');

        Auth::login($user);
        app(GantiKataSandi::class)->handle($user, ['password_lama' => 'SementaraKuat123', 'password' => 'BaruSekali123', 'password_confirmation' => 'BaruSekali123']);

        foreach (Aktivitas::all() as $a) {
            $json = json_encode($a->properties);
            // Quoted key: `wajib_ganti_password` may (and should) be logged.
            $this->assertStringNotContainsString('"password"', $json);
            $this->assertStringNotContainsString('"remember_token"', $json);
            $this->assertStringNotContainsString('SementaraKuat123', $json);
        }
        $profil = Aktivitas::where('event', 'profil.kata_sandi')->sole();
        $this->assertTrue($profil->subject->is($user));
    }

    public function test_diinput_oleh_tercatat_untuk_akun_irban(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $irban = Irban::factory()->create();
        $user = User::factory()->irban()->create();
        $user->forceFill(['irban_id' => $irban->id])->save();
        $ppj = Pegawai::factory()->create();
        $anggota = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $ppj->id])->anggota()->attach($anggota->id);

        $spt = app(SimpanSpt::class)->handle($user, null, [
            'klasifikasi_id' => Klasifikasi::where('kode', 'REGULER')->value('id'), 'diinput_oleh' => $anggota->id,
            'personel' => [
                ['pegawai_id' => $ppj->id, 'peran' => 'ketua', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
                ['pegawai_id' => $anggota->id, 'peran' => 'anggota', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
            ],
        ], 'draf');

        $dibuat = Aktivitas::where('subject_type', $spt->getMorphClass())->where('subject_id', $spt->id)->where('event', 'created')->sole();
        $this->assertSame($anggota->id, $dibuat->diinput_oleh);
        $personel = Aktivitas::where('subject_type', (new SptPersonel)->getMorphClass())->where('event', 'created')->get();
        $this->assertCount(2, $personel);
        $this->assertSame([$anggota->id, $anggota->id], $personel->pluck('diinput_oleh')->all());

        // Editing the draft without the anggota logs the removed personel row with its old values.
        app(SimpanSpt::class)->handle($user, $spt, [
            'klasifikasi_id' => $spt->klasifikasi_id, 'diinput_oleh' => $anggota->id,
            'personel' => [['pegawai_id' => $ppj->id, 'peran' => 'ketua', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true]],
        ], 'draf');
        $dihapus = Aktivitas::where('subject_type', (new SptPersonel)->getMorphClass())->where('event', 'deleted')->get()
            ->filter(fn (Aktivitas $a) => $a->properties['old']['pegawai_id'] === $anggota->id);
        $this->assertCount(1, $dihapus);
        $this->assertSame($spt->id, $dihapus->first()->properties['old']['spt_id']);
        $this->assertTrue(Aktivitas::untukSpt($spt->id)->whereKey($dihapus->first()->id)->exists());
    }

    public function test_ganti_anggota_tim_mencatat_yang_dihapus(): void
    {
        $m = array_map(fn ($p) => ['pegawai_id' => Pegawai::factory()->create()->id, 'peran_default' => $p], ['supervisor_dalnis', 'ketua', 'anggota']);
        $tim = app(SaveTim::class)->handle(Irban::factory()->create(), (int) now('Asia/Jakarta')->year, null, 'Tim 1', $m);

        app(SaveTim::class)->handle($tim->irban, $tim->tahun, $tim, 'Tim 1', array_slice($m, 0, 2));

        $dihapus = Aktivitas::where('subject_type', (new TimAnggota)->getMorphClass())->where('event', 'deleted')->get();
        $this->assertContains($m[2]['pegawai_id'], $dihapus->map(fn (Aktivitas $a) => $a->properties['old']['pegawai_id'])->all());
    }

    public function test_sahkan_pkpt_mencatat_item(): void
    {
        $pkpt = Pkpt::factory()->create(['nomor_sk' => 'SK/1', 'tanggal_sk' => '2026-01-02']);
        $items = PkptItem::factory()->count(2)->create(['pkpt_id' => $pkpt->id]);

        app(SahkanPkpt::class)->handle($pkpt, User::factory()->admin()->create());

        foreach ($items as $item) {
            $a = Aktivitas::where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)->where('event', 'updated')->sole();
            $this->assertSame($item->tanggal_mulai->format('Y-m-d'), $a->properties['attributes']['tanggal_mulai_disahkan']);
            $this->assertNotNull($item->fresh()->tanggal_selesai_disahkan);
        }
    }

    public function test_tanggal_dicatat_dalam_waktu_jakarta(): void
    {
        $item = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']);
        $pkpt = $item->pkpt;

        $item->update(['tanggal_mulai' => '2026-03-03']);
        $pkpt->forceFill(['disahkan_pada' => CarbonImmutable::parse('2026-03-02 10:15:00', 'Asia/Jakarta')])->save();

        $a = Aktivitas::where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)->where('event', 'updated')->sole();
        $this->assertSame(['tanggal_mulai' => '2026-03-02'], $a->properties['old']);
        $this->assertSame(['tanggal_mulai' => '2026-03-03'], $a->properties['attributes']);
        $b = Aktivitas::where('subject_type', $pkpt->getMorphClass())->where('subject_id', $pkpt->id)->where('event', 'updated')->sole();
        $this->assertSame('2026-03-02 10:15:00', $b->properties['attributes']['disahkan_pada']);
    }

    public function test_kenaikan_nomor_counter_dicatat(): void
    {
        $counter = (new NomorCounter)->forceFill(['tahun' => 2026, 'scope' => 'global', 'nomor_terakhir' => 4]);
        $counter->save();

        $counter->increment('nomor_terakhir');

        $a = Aktivitas::where('subject_type', $counter->getMorphClass())->where('event', 'updated')->sole();
        $this->assertSame(['nomor_terakhir' => 5], $a->properties['attributes']);
    }

    public function test_atur_ulang_berulang_selalu_dicatat(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->pimpinan()->create();
        app(AturUlangKataSandi::class)->handle($admin, $user, 'SementaraKuat123');
        app(AturUlangKataSandi::class)->handle($admin, $user, 'SementaraLain456');

        $rows = Aktivitas::where('event', 'pengguna.kata_sandi')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $a) {
            $this->assertTrue($a->subject->is($user));
            $this->assertTrue($a->causer->is($admin));
            $json = json_encode($a->properties);
            foreach (['"password"', '"remember_token"', 'SementaraKuat123', 'SementaraLain456'] as $rahasia) {
                $this->assertStringNotContainsString($rahasia, $json);
            }
        }
        $this->assertSame('Kata sandi diatur ulang', LabelAktivitas::aksi('pengguna.kata_sandi'));
    }

    public function test_activitylog_clean_ditolak(): void
    {
        activity()->log('uji');
        Aktivitas::query()->toBase()->update(['created_at' => now()->subYears(5)]);
        $jumlah = Aktivitas::count();

        $this->artisan('activitylog:clean', ['--days' => 1, '--force' => true])
            ->expectsOutputToContain('Log aktivitas tidak boleh dihapus (PRD §7.12).')->assertExitCode(1);
        $this->assertSame($jumlah, Aktivitas::count());
    }

    public function test_log_tidak_bisa_diubah(): void
    {
        $a = activity()->log('uji');

        try {
            $this->expectException(LogicException::class);
            $a->update(['description' => 'diubah']);
        } finally {
            $this->assertSame('uji', $a->fresh()->description);
        }
    }

    public function test_log_tidak_bisa_dihapus(): void
    {
        $a = activity()->log('uji');

        try {
            $this->expectException(LogicException::class);
            $a->delete();
        } finally {
            $this->assertNotNull($a->fresh());
        }
    }

    public function test_pengaturan_dicatat(): void
    {
        $settings = app(Settings::class);
        $lama = $settings->get('penanda.batas_terlambat_hari');

        $settings->set('penanda.batas_terlambat_hari', 9);
        $settings->set('penanda.batas_terlambat_hari', 9);

        $a = Aktivitas::where('log_name', 'pengaturan')->sole();
        $this->assertSame($lama, $a->properties['old']['penanda.batas_terlambat_hari']);
        $this->assertSame(9, $a->properties['attributes']['penanda.batas_terlambat_hari']);
    }

    public function test_label(): void
    {
        $this->assertSame('SPT', LabelAktivitas::objek(Spt::class));
        $this->assertSame('Pengaturan Sistem', LabelAktivitas::objek(null, 'pengaturan'));
        $this->assertSame('Foto profil diubah', LabelAktivitas::aksi('profil.foto'));
        $this->assertSame('x', LabelAktivitas::aksi('x'));
        $this->assertSame('SPT data lama', LabelAktivitas::jenisImpor('spt'));
        $this->assertSame(SptKendala::class, LabelAktivitas::kelas('spt_kendala'));
        $this->assertNull(LabelAktivitas::kelas('pengaturan'));
        $this->assertSame('SPT', array_values(LabelAktivitas::opsiObjek())[0]);
    }

    public function test_untuk_spt_memuat_baris_anak(): void
    {
        $spt = Spt::factory()->diterima()->create();
        $kendala = SptKendala::factory()->create(['spt_id' => $spt->id]);
        $komentar = Komentar::factory()->create(['spt_id' => $spt->id]);
        $lain = Spt::factory()->diterima()->create();
        $dihapus = Komentar::factory()->create(['spt_id' => $spt->id]);
        $dihapus->delete();

        $ids = Aktivitas::untukSpt($spt->id)->get();
        $this->assertTrue($ids->contains(fn ($a) => $a->event === 'deleted' && $a->subject_id === $dihapus->id), 'deleted child found by its logged spt_id');
        $this->assertTrue($ids->contains(fn ($a) => $a->subject_type === $spt->getMorphClass() && $a->subject_id === $spt->id));
        $this->assertTrue($ids->contains(fn ($a) => $a->subject_type === $kendala->getMorphClass() && $a->subject_id === $kendala->id));
        $this->assertTrue($ids->contains(fn ($a) => $a->subject_type === $komentar->getMorphClass() && $a->subject_id === $komentar->id));
        $this->assertFalse($ids->contains(fn ($a) => $a->subject_type === $lain->getMorphClass() && $a->subject_id === $lain->id));

        // The column is filled when the row is written: SPT = its own id, child = its spt_id (an update row has no spt_id in its properties), others null.
        $kendala->update(['uraian' => 'diubah']);
        $diubah = Aktivitas::where('subject_type', $kendala->getMorphClass())->where('event', 'updated')->sole();
        $this->assertArrayNotHasKey('spt_id', $diubah->properties['attributes']);
        $this->assertSame($spt->id, $diubah->spt_id);
        $this->assertSame($spt->id, Aktivitas::where('subject_type', $spt->getMorphClass())->where('subject_id', $spt->id)->value('spt_id'));
        $this->assertSame($spt->id, Aktivitas::where('subject_type', $dihapus->getMorphClass())->where('event', 'deleted')->value('spt_id'));
        $this->assertSame(0, Aktivitas::whereNotNull('spt_id')->whereNotIn('subject_type', [$spt->getMorphClass(), $kendala->getMorphClass(), $komentar->getMorphClass()])->count());
    }

    public function test_simpan_ulang_tim_tanpa_perubahan_tidak_mencatat_anggota(): void
    {
        $m = array_map(fn ($p) => ['pegawai_id' => Pegawai::factory()->create()->id, 'peran_default' => $p], ['supervisor_dalnis', 'ketua', 'anggota']);
        $tim = app(SaveTim::class)->handle(Irban::factory()->create(), (int) now('Asia/Jakarta')->year, null, 'Tim 1', $m);
        $anggota = fn () => Aktivitas::where('subject_type', (new TimAnggota)->getMorphClass())->count();
        $this->assertSame(3, $anggota(), 'kontrol: pembuatan mencatat tiga anggota');

        app(SaveTim::class)->handle($tim->irban, $tim->tahun, $tim, 'Tim 1', $m);
        $this->assertSame(3, $anggota());

        app(SaveTim::class)->handle($tim->irban, $tim->tahun, $tim, 'Tim 1', array_slice($m, 0, 2));
        $this->assertSame(4, $anggota(), 'hanya anggota yang dihapus tercatat');
    }
}
