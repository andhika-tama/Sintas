<?php

namespace Tests\Feature\PerubahanPenugasan;

use App\Actions\Pkpt\Perubahan\AjukanPerubahan;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\Perubahan\PutuskanPerubahan;
use App\Actions\Pkpt\Perubahan\SimpanOperasi;
use App\Actions\PerubahanPenugasan\AjukanPerubahanPenugasan;
use App\Actions\PerubahanPenugasan\PutuskanPerubahanPenugasan;
use App\Actions\Spt\SimpanSpt;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahanPenugasan;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\PerubahanPenugasanDiajukan;
use App\Notifications\PerubahanPenugasanDiputuskan;
use App\Services\PenandaSpt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** 1.2 Task 6: Perubahan Penugasan (Irban asks, Pimpinan decides), K6/K8 kesesuaian, K10 clearing, R2, K13. */
class PerubahanPenugasanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pimpinan;

    private User $pimpinan2;

    private User $irban;

    private User $irbanLain;

    private Irban $irban1;

    private Irban $irban2;

    private Pegawai $penginput;

    private Pkpt $pkpt;

    private PkptItem $a;

    private PkptItem $milikLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 3, 25));
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->pimpinan2 = User::factory()->pimpinan()->create();
        $this->irban1 = Irban::factory()->create(['nama' => 'Irban I']);
        $this->irban2 = Irban::factory()->create(['nama' => 'Irban II']);
        $this->irban = User::factory()->irban()->create(['irban_id' => $this->irban1->id]);
        $this->irbanLain = User::factory()->irban()->create(['irban_id' => $this->irban2->id]);
        $this->penginput = Pegawai::factory()->create(['email' => 'penginput@example.test']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban1->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
        $this->pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu']);
        $item = fn (Irban $i, string $mulai, string $selesai) => PkptItem::factory()->disahkanDari()->create([
            'pkpt_id' => $this->pkpt->id, 'irban_id' => $i->id, 'jenis_penugasan_id' => $jenis->id, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]);
        $this->a = $item($this->irban1, '2026-03-02', '2026-03-20');
        $this->milikLain = $item($this->irban2, '2026-08-03', '2026-08-14');
    }

    private function ajukan(?PkptItem $item = null, array $data = [], ?User $user = null): PerubahanPenugasan
    {
        return app(AjukanPerubahanPenugasan::class)->handle($user ?? $this->irban, $item ?? $this->a, $data + [
            'tanggal_mulai' => '2026-04-06', 'tanggal_selesai' => '2026-04-24', 'alasan' => 'OPD sedang audit BPK.', 'diinput_oleh' => $this->penginput->id]);
    }

    private function putuskan(PerubahanPenugasan $p, bool $setuju, ?string $catatan = null, ?User $user = null): PerubahanPenugasan
    {
        return app(PutuskanPerubahanPenugasan::class)->handle($user ?? $this->pimpinan, $p, $setuju, $catatan);
    }

    private function galat(callable $fn, string $key, string $pesan): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertSame($pesan, $e->errors()[$key][0] ?? null, json_encode($e->errors()));

            return;
        }
        $this->fail("Expected a validation error on {$key}.");
    }

    private function sptDiterima(string $mulai, string $selesai): Spt
    {
        return Spt::factory()->diterima()->create(['irban_id' => $this->irban1->id, 'pkpt_item_id' => $this->a->id,
            'jenis_penugasan_id' => $this->a->jenis_penugasan_id, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]);
    }

    public function test_irban_ajukan(): void
    {
        $p = $this->ajukan();

        $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->status);
        $this->assertSame([$this->a->id, $this->irban1->id, $this->irban->id, $this->penginput->id, '2026-04-06', '2026-04-24', 'OPD sedang audit BPK.'],
            [$p->pkpt_item_id, $p->irban_id, $p->diajukan_oleh, $p->diinput_oleh, $p->tanggal_mulai->toDateString(), $p->tanggal_selesai->toDateString(), $p->alasan]);
        Notification::assertSentTo([$this->pimpinan, $this->pimpinan2], PerubahanPenugasanDiajukan::class,
            fn ($n) => $n->toArray($this->pimpinan)['jenis'] === 'perubahan_penugasan_diajukan' && $n->toArray($this->pimpinan)['url'] === PerubahanPenugasanDiajukan::URL);
        Notification::assertNotSentTo([$this->admin, $this->irban], PerubahanPenugasanDiajukan::class);
        $this->assertNull($this->a->fresh()->perubahan_penugasan_id);
    }

    public function test_alasan_wajib(): void
    {
        $this->galat(fn () => $this->ajukan(data: ['alasan' => '   ']), 'alasan', 'Isi alasan perubahan.');
        $this->galat(fn () => $this->ajukan(data: ['alasan' => str_repeat('a', 1001)]), 'alasan', 'Alasan maksimal 1000 karakter.');
        $this->galat(fn () => $this->ajukan(data: ['diinput_oleh' => null]), 'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->assertSame(0, PerubahanPenugasan::count());
    }

    public function test_tanggal_sama_dengan_item(): void
    {
        $this->galat(fn () => $this->ajukan(data: ['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']), 'tanggal_mulai', 'Jadwal baru sama dengan jadwal PKPT.');
        $this->galat(fn () => $this->ajukan(data: ['tanggal_mulai' => '2027-01-04', 'tanggal_selesai' => '2027-01-08']), 'tanggal_mulai', 'Tanggal harus berada di tahun 2026.');
        $this->galat(fn () => $this->ajukan(data: ['tanggal_mulai' => '']), 'tanggal_mulai', 'Tanggal mulai wajib diisi.');
        $this->galat(fn () => $this->ajukan(data: ['tanggal_mulai' => '2026-04-10', 'tanggal_selesai' => '2026-04-06']), 'tanggal_selesai',
            'Tanggal selesai tidak boleh sebelum tanggal mulai.');
    }

    public function test_satu_diajukan_per_item(): void
    {
        $p = $this->ajukan();
        $this->galat(fn () => $this->ajukan(data: ['tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-08']), 'item',
            'Masih ada Perubahan Penugasan yang menunggu keputusan untuk item ini.');

        // R2: after a decision the irban may submit again; the latest approval is the reference.
        $this->putuskan($p, true);
        $kedua = $this->ajukan(data: ['tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-08']);
        $this->putuskan($kedua, true);
        $this->assertSame($kedua->id, $this->a->fresh()->perubahan_penugasan_id);
        $this->assertSame(2, PerubahanPenugasan::where('status', StatusPerubahanPenugasan::Disetujui->value)->count());
    }

    public function test_item_irban_lain_404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->ajukan($this->milikLain);
    }

    public function test_item_dihapus_saat_diajukan_toast(): void
    {
        $this->a->forceFill(['status' => StatusItemPkpt::Dihapus])->save();
        $this->galat(fn () => $this->ajukan(), 'item', PutuskanPerubahanPenugasan::ITEM_BASI);
    }

    public function test_pkpt_belum_disahkan_ditolak(): void
    {
        $draf = PkptItem::factory()->create(['pkpt_id' => Pkpt::factory()->create(['tahun' => 2031])->id, 'irban_id' => $this->irban1->id,
            'tanggal_mulai' => '2031-03-02', 'tanggal_selesai' => '2031-03-06']);
        $this->galat(fn () => $this->ajukan($draf, ['tanggal_mulai' => '2031-04-01', 'tanggal_selesai' => '2031-04-04']), 'pkpt',
            'Perubahan Penugasan hanya untuk PKPT yang sudah disahkan.');
    }

    public function test_non_irban_403(): void
    {
        foreach ([$this->admin, $this->pimpinan, User::factory()->bagianUmum()->create()] as $user) {
            try {
                $this->ajukan(user: $user);
                $this->fail("{$user->role->value} may not ajukan.");
            } catch (AuthorizationException) {
                $this->assertSame(0, PerubahanPenugasan::count());
            }
        }
    }

    public function test_pimpinan_setujui(): void
    {
        $p = $this->putuskan($this->ajukan(), true);

        $this->assertSame(StatusPerubahanPenugasan::Disetujui, $p->status);
        $this->assertSame($this->pimpinan->id, $p->diputuskan_oleh);
        $this->assertNotNull($p->diputuskan_pada);
        $this->assertNull($p->catatan_putusan);
        $a = $this->a->fresh();
        $this->assertSame($p->id, $a->perubahan_penugasan_id);
        $this->assertSame($p->id, $a->perubahanPenugasan->id);
        $this->assertSame(['2026-03-02', '2026-03-20'], [$a->tanggal_mulai->toDateString(), $a->tanggal_selesai->toDateString()]);
        Notification::assertSentTo($this->irban, PerubahanPenugasanDiputuskan::class, function ($n) {
            $d = $n->toArray($this->irban);

            return $d['jenis'] === 'perubahan_penugasan_disetujui' && $d['url'] === route('irban.pkpt', absolute: false);
        });
        Notification::assertNotSentTo([$this->irbanLain, $this->admin], PerubahanPenugasanDiputuskan::class);
        $this->assertDatabaseHas('activity_log', ['subject_type' => PerubahanPenugasan::class, 'subject_id' => $p->id, 'event' => 'updated']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => PkptItem::class, 'subject_id' => $a->id, 'event' => 'updated']);
    }

    public function test_pimpinan_tolak_wajib_catatan(): void
    {
        $p = $this->ajukan();
        $this->galat(fn () => $this->putuskan($p, false, '  '), 'catatan', 'Isi catatan penolakan.');
        $this->galat(fn () => $this->putuskan($p, false, str_repeat('a', 1001)), 'catatan', 'Catatan maksimal 1000 karakter.');
        $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->fresh()->status);

        $p = $this->putuskan($p, false, 'Jadwal OPD tidak berubah.');
        $this->assertSame([StatusPerubahanPenugasan::Ditolak, 'Jadwal OPD tidak berubah.'], [$p->status, $p->catatan_putusan]);
        $this->assertNull($this->a->fresh()->perubahan_penugasan_id);
        Notification::assertSentTo($this->irban, PerubahanPenugasanDiputuskan::class,
            fn ($n) => $n->toArray($this->irban)['jenis'] === 'perubahan_penugasan_ditolak' && $n->toArray($this->irban)['catatan'] === 'Jadwal OPD tidak berubah.');
    }

    public function test_putuskan_dua_kali(): void
    {
        $p = $this->ajukan();
        $this->putuskan($p, true);
        // The stale tab still holds the Diajukan model.
        $this->galat(fn () => $this->putuskan($p, false, 'Terlambat.', $this->pimpinan2), 'perubahan', 'Perubahan Penugasan ini sudah diputuskan.');
        $this->assertSame(PutuskanPerubahanPenugasan::SUDAH_DIPUTUSKAN, 'Perubahan Penugasan ini sudah diputuskan.');
    }

    public function test_item_dihapus_sebelum_diputuskan(): void
    {
        $this->assertSame('Item PKPT ini sudah dihapus atau dipindahkan oleh Perubahan PKPT.', PutuskanPerubahanPenugasan::ITEM_BASI);
        $p = $this->ajukan();
        $this->a->forceFill(['status' => StatusItemPkpt::Dihapus])->save();
        $this->galat(fn () => $this->putuskan($p, true), 'perubahan', PutuskanPerubahanPenugasan::ITEM_BASI);
        $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->fresh()->status);

        $q = $this->ajukan($b = PkptItem::factory()->create(['pkpt_id' => $this->pkpt->id, 'irban_id' => $this->irban1->id]));
        $b->forceFill(['irban_id' => $this->irban2->id])->save();
        $this->galat(fn () => $this->putuskan($q, true), 'perubahan', PutuskanPerubahanPenugasan::ITEM_BASI);
        $this->assertNull($b->fresh()->perubahan_penugasan_id);

        // Rejection still clears the queue (no orphan Diajukan request).
        $this->assertSame(StatusPerubahanPenugasan::Ditolak, $this->putuskan($p, false, 'Item sudah dihapus.')->status);
    }

    public function test_admin_irban_tidak_bisa_putuskan(): void
    {
        $p = $this->ajukan();
        foreach ([$this->admin, $this->irban, User::factory()->bagianUmum()->create()] as $user) {
            try {
                $this->putuskan($p, true, null, $user);
                $this->fail("{$user->role->value} may not putuskan.");
            } catch (AuthorizationException) {
                $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->fresh()->status);
            }
        }

        // K13: only Pimpinan and the owning Irban see it.
        $lihat = fn (User $u) => Gate::forUser($u)->allows('view', $p);
        $this->assertSame([true, true, false, false, false], [$lihat($this->pimpinan), $lihat($this->irban), $lihat($this->irbanLain),
            $lihat($this->admin), $lihat(User::factory()->bagianUmum()->create())]);
    }

    public function test_spt_tidak_sesuai_walau_dalam_rentang(): void
    {
        $spt = $this->sptDiterima('2026-03-04', '2026-03-06');
        $penanda = app(PenandaSpt::class);
        $this->assertNotContains('tidak_sesuai_pkpt', $penanda->untuk($spt));

        $this->putuskan($this->ajukan(), true);
        $spt = $spt->fresh();
        $this->assertContains('tidak_sesuai_pkpt', $penanda->untuk($spt));
        $this->assertTrue($penanda->perluKendala($spt));

        $lain = [$this->sptDiterima('2026-03-09', '2026-03-10'), $this->sptDiterima('2026-04-06', '2026-04-08')];
        $hitung = function ($spts) use ($penanda) {
            $spts = collect($spts)->map->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $hasil = $penanda->untukBanyak($spts);
            DB::disableQueryLog();

            return [$hasil, count(DB::getQueryLog())];
        };
        [$satu, $q1] = $hitung([$spt]);
        [$semua, $q3] = $hitung([$spt, ...$lain]);
        $this->assertSame($q1, $q3);
        $this->assertSame($penanda->untuk($spt), $satu[$spt->id]);
        foreach ($semua as $flags) {
            $this->assertContains('tidak_sesuai_pkpt', $flags);
        }
    }

    public function test_perubahan_pkpt_resmi_menghapus_tanda(): void
    {
        $this->putuskan($this->ajukan(), true);
        $spt = $this->sptDiterima('2026-04-07', '2026-04-09');
        $admin = $this->admin;
        $paket = function (array $op) use ($admin) {
            $p = app(BuatPerubahan::class)->handle($admin, $this->pkpt, ['jenis' => 'perubahan', 'alasan' => 'Menyesuaikan jadwal.']);
            app(SimpanOperasi::class)->handle($admin, $p, null, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id] + $op);

            return app(PutuskanPerubahan::class)->handle($this->pimpinan, app(AjukanPerubahan::class)->handle($admin, $p), true, null);
        };

        // An official change that leaves the dates alone keeps the mark.
        $paket(['keterangan' => 'Catatan baru']);
        $this->assertNotNull($this->a->fresh()->perubahan_penugasan_id);

        $paket(['tanggal_mulai' => '2026-04-06', 'tanggal_selesai' => '2026-04-24']);
        $a = $this->a->fresh();
        $this->assertNull($a->perubahan_penugasan_id);
        $this->assertSame(['2026-04-06', '2026-04-24'], [$a->tanggal_mulai->toDateString(), $a->tanggal_selesai->toDateString()]);
        $this->assertNotContains('tidak_sesuai_pkpt', app(PenandaSpt::class)->untuk($spt->fresh()));
    }

    public function test_peringatan_form(): void
    {
        $data = ['pkpt_item_id' => $this->a->id, 'tanggal_mulai' => '2026-03-04', 'tanggal_selesai' => '2026-03-06'];
        $simpan = app(SimpanSpt::class);
        $this->assertSame([], $simpan->peringatan($data, $this->irban1->id));

        $this->putuskan($this->ajukan(), true);
        $this->assertSame(['Jadwal item ini diubah lewat Perubahan Penugasan. SPT akan ditandai Tidak Sesuai PKPT.'],
            $simpan->peringatan($data, $this->irban1->id));
        $this->assertSame([], $simpan->peringatan($data, $this->irban2->id));
    }
}
