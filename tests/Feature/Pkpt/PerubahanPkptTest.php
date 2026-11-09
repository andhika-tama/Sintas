<?php

namespace Tests\Feature\Pkpt;

use App\Actions\Pkpt\Perubahan\AjukanPerubahan;
use App\Actions\Pkpt\Perubahan\AturPengingatSk;
use App\Actions\Pkpt\Perubahan\BuangPerubahan;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\Perubahan\HapusOperasi;
use App\Actions\Pkpt\Perubahan\PutuskanPerubahan;
use App\Actions\Pkpt\Perubahan\SimpanOperasi;
use App\Actions\Pkpt\Perubahan\SimpanSkPerubahan;
use App\Enums\AksiPerubahan;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahan;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\PenandaSpt;
use App\Services\PerubahanPkpt;
use Database\Factories\PkptPerubahanItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\PdfUji;
use Tests\TestCase;

/** Fase 9.2 Task 6: Perubahan PKPT rules, application on approval, K7, SK. */
class PerubahanPkptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pimpinan;

    private Pkpt $pkpt;

    private Irban $irban1;

    private Irban $irban2;

    private JenisPenugasan $jenis;

    private PkptItem $a;

    private PkptItem $b;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $this->irban1 = Irban::factory()->create(['nama' => 'Irban I']);
        $this->irban2 = Irban::factory()->create(['nama' => 'Irban II']);
        $this->jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu']);
        $item = fn (array $a) => PkptItem::factory()->disahkanDari()->create([
            'pkpt_id' => $this->pkpt->id, 'irban_id' => $this->irban1->id, 'jenis_penugasan_id' => $this->jenis->id] + $a);
        $this->a = $item(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20', 'keterangan' => 'Awal']);
        $this->b = $item(['tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-15']);
    }

    private function buat(): PkptPerubahan
    {
        return app(BuatPerubahan::class)->handle($this->admin, $this->pkpt, ['jenis' => 'perubahan', 'alasan' => ' Menyesuaikan jadwal. ']);
    }

    private function op(PkptPerubahan $p, array $data, ?PkptPerubahanItem $op = null): PkptPerubahanItem
    {
        return app(SimpanOperasi::class)->handle($this->admin, $p, $op, $data);
    }

    private function tambahData(array $timpa = []): array
    {
        return array_merge(['aksi' => 'tambah', 'irban_id' => $this->irban2->id, 'jenis_penugasan_id' => $this->jenis->id,
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-07-10', 'keterangan' => 'Baru', 'target_spt' => 2], $timpa);
    }

    private function ajukan(PkptPerubahan $p): PkptPerubahan
    {
        return app(AjukanPerubahan::class)->handle($this->admin, $p);
    }

    private function putuskan(PkptPerubahan $p, bool $setujui, ?string $komentar = null): PkptPerubahan
    {
        return app(PutuskanPerubahan::class)->handle($this->pimpinan, $p, $setujui, $komentar);
    }

    /** @return array<string, list<string>> */
    private function galat(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('ValidationException diharapkan.');
    }

    private function kejadian(PkptPerubahan $p): array
    {
        return $p->riwayat()->orderBy('id')->pluck('kejadian')->all();
    }

    public function test_each_aksi_is_applied_on_approval(): void
    {
        $p = $this->buat();
        $this->assertSame('Menyesuaikan jadwal.', $p->alasan);
        $tambah = $this->op($p, $this->tambahData());
        $ubah = $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'tanggal_mulai' => '2026-04-01', 'tanggal_selesai' => '2026-04-10']);
        $hapus = $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->b->id]);
        $this->assertSame([1, 2, 3], [$tambah->urutan, $ubah->urutan, $hapus->urutan]);
        // ubah keeps the fields it did not send.
        $this->assertSame('Awal', $ubah->data_baru['keterangan']);
        $this->assertSame([], $hapus->data_baru);

        $p = $this->putuskan($this->ajukan($p), true);

        $this->assertSame(StatusPerubahan::Disetujui, $p->status);
        $this->assertSame(1, $p->nomor_urut);
        $baru = PkptItem::findOrFail($tambah->fresh()->pkpt_item_id);
        $this->assertSame($p->id, $baru->ditambahkan_perubahan_id);
        $this->assertSame([$this->irban2->id, '2026-07-01', '2026-07-10', '2026-07-01', '2026-07-10', 2, 'Baru'], [$baru->irban_id,
            $baru->tanggal_mulai->toDateString(), $baru->tanggal_selesai->toDateString(),
            $baru->tanggal_mulai_disahkan->toDateString(), $baru->tanggal_selesai_disahkan->toDateString(), $baru->target_spt, $baru->keterangan]);
        $this->assertFalse($baru->direvisi());

        $a = $this->a->fresh();
        $this->assertSame(['2026-04-01', '2026-04-10'], [$a->tanggal_mulai->toDateString(), $a->tanggal_selesai->toDateString()]);
        $this->assertTrue($a->direvisi());
        $this->assertSame('2026-03-02', $ubah->fresh()->data_lama['tanggal_mulai']);

        $b = $this->b->fresh();
        $this->assertSame(StatusItemPkpt::Dihapus, $b->status);
        $this->assertSame($p->id, $b->dihapus_perubahan_id);
        $this->assertSame('2026-05-04', $hapus->fresh()->data_lama['tanggal_mulai']);
        $this->assertSame(['dibuat', 'diajukan', 'disetujui'], $this->kejadian($p));

        $riwayat = app(PerubahanPkpt::class)->riwayatItem([$this->a->id, $this->b->id, $baru->id]);
        $this->assertSame(AksiPerubahan::Ubah, $riwayat[$this->a->id][0]['aksi']);
        $this->assertSame(1, $riwayat[$this->a->id][0]['nomor_urut']);
        $this->assertFalse($riwayat[$this->a->id][0]['ada_sk']);
        $this->assertCount(1, $riwayat[$baru->id]);
    }

    public function test_data_lama_is_taken_at_approval_and_nomor_urut_counts_per_pkpt(): void
    {
        $p1 = $this->ajukan(tap($this->buat(), fn ($p) => $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'tanggal_mulai' => '2026-03-09'])));
        // A second package (made directly: only one may be open through the actions) changes the item first.
        $p2 = PkptPerubahan::factory()->diajukan()->create(['pkpt_id' => $this->pkpt->id]);
        PkptPerubahanItem::factory()->create(['perubahan_id' => $p2->id, 'pkpt_item_id' => $this->a->id,
            'data_baru' => PkptPerubahanItemFactory::data($this->a, ['keterangan' => 'Diubah lebih dulu'])]);

        $this->assertSame(1, $this->putuskan($p2, true)->nomor_urut);
        $p1 = $this->putuskan($p1, true);

        $this->assertSame(2, $p1->nomor_urut);
        $lama = $p1->operasi()->first()->data_lama;
        $this->assertSame('Diubah lebih dulu', $lama['keterangan']);
        $this->assertSame('2026-03-02', $lama['tanggal_mulai']);
        // data_baru was drafted before p2: applying it restores the keterangan it carried.
        $this->assertSame(['2026-03-09', 'Awal'], [$this->a->fresh()->tanggal_mulai->toDateString(), $this->a->fresh()->keterangan]);
        // Numbering is per PKPT: another PKPT starts at 1.
        $lain = PkptPerubahan::factory()->diajukan()->create();
        PkptPerubahanItem::factory()->create(['perubahan_id' => $lain->id, 'aksi' => 'tambah', 'pkpt_item_id' => null,
            'data_baru' => ['irban_id' => $this->irban1->id, 'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => $lain->pkpt->tahun.'-02-01',
                'tanggal_selesai' => $lain->pkpt->tahun.'-02-02', 'keterangan' => null, 'target_spt' => null]]);
        $this->assertSame(1, $this->putuskan($lain, true)->nomor_urut);
    }

    public function test_only_one_open_package_per_pkpt(): void
    {
        $p = $this->buat();
        $this->assertSame(['perubahan' => [BuatPerubahan::SUDAH_ADA]], $this->galat(fn () => $this->buat()));
        $this->assertSame($p->id, app(PerubahanPkpt::class)->terbuka($this->pkpt)?->id);

        $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->b->id]);
        $this->putuskan($this->ajukan($p), true);
        $this->assertNull(app(PerubahanPkpt::class)->terbuka($this->pkpt));
        $this->assertNotSame($p->id, $this->buat()->id);
    }

    public function test_draf_pkpt_and_invalid_header_are_rejected(): void
    {
        $draf = Pkpt::factory()->create(['tahun' => 2027]);
        $this->assertSame(['pkpt' => [PerubahanPkpt::BELUM_DISAHKAN]],
            $this->galat(fn () => app(BuatPerubahan::class)->handle($this->admin, $draf, ['jenis' => 'perubahan', 'alasan' => 'x'])));
        $e = $this->galat(fn () => app(BuatPerubahan::class)->handle($this->admin, $this->pkpt, ['jenis' => 'lain', 'alasan' => '  ']));
        $this->assertSame(['Pilih jenis perubahan.'], $e['jenis']);
        $this->assertSame(['Alasan wajib diisi.'], $e['alasan']);
        $e = $this->galat(fn () => app(BuatPerubahan::class)->handle($this->admin, $this->pkpt, ['jenis' => 'koreksi_input', 'alasan' => str_repeat('a', 2001)]));
        $this->assertSame(['Alasan maksimal 2000 karakter.'], $e['alasan']);
        $this->assertSame(0, PkptPerubahan::count());
    }

    public function test_k7_locks_irban_and_jenis_and_forbids_removal_of_items_used_by_spt(): void
    {
        Spt::factory()->create(['irban_id' => $this->irban1->id, 'pkpt_item_id' => $this->a->id]); // draf SPT counts too
        $p = $this->buat();
        $lain = JenisPenugasan::factory()->create();

        $this->assertSame(['irban_id' => [PerubahanPkpt::K7_UBAH]], $this->galat(fn () => $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'irban_id' => $this->irban2->id])));
        $this->assertSame(['irban_id' => [PerubahanPkpt::K7_UBAH]], $this->galat(fn () => $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'jenis_penugasan_id' => $lain->id])));
        $this->assertSame(['pkpt_item_id' => [PerubahanPkpt::K7_HAPUS]], $this->galat(fn () => $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->a->id])));
        $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'tanggal_mulai' => '2026-03-03', 'keterangan' => 'Geser']);
        $this->assertSame([], app(PerubahanPkpt::class)->cek($p));
        $this->assertSame([$this->a->id => true, $this->b->id => false], app(PerubahanPkpt::class)->punyaSpt([$this->a->id, $this->b->id]));
    }

    public function test_operation_lookup_is_scoped_and_one_operation_per_item(): void
    {
        $p = $this->buat();
        $asing = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-03']);
        $dihapus = PkptItem::factory()->dihapus()->create(['pkpt_id' => $this->pkpt->id]);
        $this->assertSame(['pkpt_item_id' => [PerubahanPkpt::ITEM_TIDAK_ADA]], $this->galat(fn () => $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $asing->id])));
        $this->assertSame(['pkpt_item_id' => [PerubahanPkpt::ITEM_TIDAK_ADA]], $this->galat(fn () => $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $dihapus->id])));
        $this->assertSame(['aksi' => ['Pilih aksi.']], $this->galat(fn () => $this->op($p, ['aksi' => 'geser'])));
        $op = $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->b->id]);
        $this->assertSame(['pkpt_item_id' => [PerubahanPkpt::ITEM_GANDA]], $this->galat(fn () => $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->b->id, 'keterangan' => 'x'])));
        // Editing the same operation into an ubah of the same item is allowed.
        $op = $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->b->id, 'keterangan' => 'x'], $op);
        $this->assertSame(AksiPerubahan::Ubah, $op->aksi);
        $this->assertSame(1, $p->operasi()->count());
    }

    public function test_cek_returns_the_same_messages_the_actions_throw(): void
    {
        $p = $this->buat();
        $this->assertSame([PerubahanPkpt::KOSONG], app(PerubahanPkpt::class)->cek($p));
        $this->assertSame(['perubahan' => [PerubahanPkpt::KOSONG]], $this->galat(fn () => $this->ajukan($p)));

        $tahun = 'Tanggal harus berada di tahun 2026.';
        $this->assertSame([$tahun], $this->galat(fn () => $this->op($p, $this->tambahData(['tanggal_selesai' => '2027-01-05'])))['tanggal_selesai']);
        // Fase 11.2 Task 6 butir 7: its own key, so the screen keeps it in the modal without comparing message text.
        $this->assertSame(['operasi' => [PerubahanPkpt::TIDAK_BERUBAH]], $this->galat(fn () => $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id])));

        // Rows written before the rules changed (or by hand) are caught by cek with the operation label.
        PkptPerubahanItem::factory()->create(['perubahan_id' => $p->id, 'aksi' => 'tambah', 'pkpt_item_id' => null, 'urutan' => 1,
            'data_baru' => ['irban_id' => $this->irban2->id, 'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-01-05']]);
        PkptPerubahanItem::factory()->create(['perubahan_id' => $p->id, 'aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'urutan' => 2,
            'data_baru' => PkptPerubahanItemFactory::data($this->a)]);
        Spt::factory()->create(['pkpt_item_id' => $this->b->id]);
        PkptPerubahanItem::factory()->create(['perubahan_id' => $p->id, 'aksi' => 'hapus', 'pkpt_item_id' => $this->b->id, 'urutan' => 3, 'data_baru' => []]);
        PkptPerubahanItem::factory()->create(['perubahan_id' => $p->id, 'aksi' => 'hapus', 'pkpt_item_id' => $this->b->id, 'urutan' => 4, 'data_baru' => []]);

        $this->assertSame([
            "Operasi 1 (Tambah item · Reviu · Irban II): {$tahun}",
            'Operasi 2 (Ubah item · Reviu · Irban I): '.PerubahanPkpt::TIDAK_BERUBAH,
            'Operasi 3 (Hapus item · Reviu · Irban I): '.PerubahanPkpt::K7_HAPUS,
            'Operasi 4 (Hapus item · Reviu · Irban I): '.PerubahanPkpt::K7_HAPUS,
            'Operasi 4 (Hapus item · Reviu · Irban I): '.PerubahanPkpt::ITEM_GANDA,
        ], app(PerubahanPkpt::class)->cek($p));
    }

    public function test_spt_created_after_submission_blocks_approval_then_rejection_and_resubmission_work(): void
    {
        $p = $this->buat();
        $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->a->id]);
        $p = $this->ajukan($p);
        Spt::factory()->create(['pkpt_item_id' => $this->a->id]);

        $e = $this->galat(fn () => $this->putuskan($p, true));
        $this->assertSame(['Operasi 1 (Hapus item · Reviu · Irban I): '.PerubahanPkpt::K7_HAPUS], $e['perubahan']);
        $this->assertSame(StatusPerubahan::Diajukan, $p->fresh()->status);
        $this->assertNull($p->fresh()->nomor_urut);
        $this->assertSame(StatusItemPkpt::Aktif, $this->a->fresh()->status);

        $this->assertSame(['komentar' => ['Alasan penolakan wajib diisi.']], $this->galat(fn () => $this->putuskan($p, false, '  ')));
        $p = $this->putuskan($p, false, 'Item sudah dipakai SPT.');
        $this->assertSame(StatusPerubahan::Ditolak, $p->status);
        $this->assertSame(['perubahan' => [PutuskanPerubahan::SUDAH_DIPUTUSKAN]], $this->galat(fn () => $this->putuskan($p, false, null)));

        // Ditolak: editable and re-submittable; the rejection komentar stays.
        app(HapusOperasi::class)->handle($this->admin, $p->operasi()->first());
        $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'tanggal_selesai' => '2026-03-27']);
        app(BuatPerubahan::class)->perbarui($this->admin, $p, ['jenis' => 'koreksi_input', 'alasan' => 'Koreksi.']);
        $p = $this->ajukan($p->fresh());
        $this->assertSame([StatusPerubahan::Diajukan, 'Item sudah dipakai SPT.', 'Koreksi.'], [$p->status, $p->komentar_putusan, $p->alasan]);
        $this->assertSame('Item sudah dipakai SPT.', $p->riwayat()->where('kejadian', 'ditolak')->value('catatan'));
        $this->assertSame(['dibuat', 'diajukan', 'ditolak', 'diajukan'], $this->kejadian($p));
        $this->assertSame(['perubahan' => [SimpanOperasi::BASI]], $this->galat(fn () => $this->op($p, $this->tambahData())));
        $this->assertSame('2026-03-27', $this->putuskan($p, true)->operasi()->first()->item->tanggal_selesai->toDateString());
    }

    public function test_buang_only_for_draf_or_ditolak_never_approved(): void
    {
        Storage::fake('local');
        $draf = $this->buat();
        app(SimpanSkPerubahan::class)->handle($this->admin, $draf, ['nomor_sk' => 'SK/1'], PdfUji::buat('sk.pdf', 10));
        $berkas = $draf->fresh()->berkas_sk;
        Storage::disk('local')->assertExists($berkas);
        app(BuangPerubahan::class)->handle($this->admin, $draf);
        $this->assertNull(PkptPerubahan::find($draf->id));
        Storage::disk('local')->assertMissing($berkas);

        $ditolak = PkptPerubahan::factory()->ditolak()->create(['pkpt_id' => $this->pkpt->id]);
        app(BuangPerubahan::class)->handle($this->admin, $ditolak);
        $this->assertNull(PkptPerubahan::find($ditolak->id));

        foreach ([PkptPerubahan::factory()->diajukan(), PkptPerubahan::factory()->disetujui(5)] as $f) {
            $p = $f->create(['pkpt_id' => $this->pkpt->id]);
            $this->assertSame(['perubahan' => [BuangPerubahan::BASI]], $this->galat(fn () => app(BuangPerubahan::class)->handle($this->admin, $p)));
            $this->assertNotNull($p->fresh());
        }
    }

    public function test_only_sk_fields_change_after_approval_and_every_event_is_recorded(): void
    {
        Storage::fake('local');
        $p = $this->buat();
        $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->b->id]);
        $p = $this->putuskan($this->ajukan($p), true);
        $this->assertNull($p->sk_lengkap_pada);

        $this->assertSame(['perubahan' => [SimpanOperasi::BASI]], $this->galat(fn () => $this->op($p, $this->tambahData())));
        $this->assertSame(['perubahan' => [SimpanOperasi::BASI]], $this->galat(fn () => app(BuatPerubahan::class)->perbarui($this->admin, $p, ['jenis' => 'perubahan', 'alasan' => 'x'])));
        $this->assertSame(['perubahan' => [SimpanOperasi::BASI]], $this->galat(fn () => app(HapusOperasi::class)->handle($this->admin, $p->operasi()->first())));

        $sk = app(SimpanSkPerubahan::class);
        $e = $this->galat(fn () => $sk->handle($this->admin, $p, ['nomor_sk' => str_repeat('1', 256), 'tanggal_sk' => '2026-02-31'], UploadedFile::fake()->create('sk.docx', 10, 'application/msword')));
        $this->assertSame(['nomor_sk', 'tanggal_sk', 'berkas_sk'], array_keys($e));
        $p = $sk->handle($this->admin, $p, ['nomor_sk' => 'SK/9', 'tanggal_sk' => '2026-06-01'], null);
        $this->assertNull($p->sk_lengkap_pada);
        $p = $sk->handle($this->admin, $p, ['nomor_sk' => 'SK/9', 'tanggal_sk' => '2026-06-01'], $pdf = PdfUji::buat('sk.pdf', 10));
        $this->assertNotNull($p->sk_lengkap_pada);
        $this->assertSame(hash_file('sha256', $pdf->getRealPath()), $p->hash_sk);
        $this->assertMatchesRegularExpression("#^perubahan-pkpt/{$p->id}/[0-9a-f-]{36}\\.pdf$#", $p->berkas_sk);
        $lama = $p->berkas_sk;
        $p = $sk->handle($this->admin, $p, ['nomor_sk' => 'SK/9', 'tanggal_sk' => '2026-06-01'], PdfUji::buat('sk2.pdf', 10));
        Storage::disk('local')->assertMissing($lama);
        Storage::disk('local')->assertExists($p->berkas_sk);
        $p = $sk->handle($this->admin, $p, ['nomor_sk' => '', 'tanggal_sk' => '2026-06-01'], null);
        $this->assertNull($p->sk_lengkap_pada);
        $this->assertSame(['Diubah: nomor SK, tanggal SK.', 'Diubah: berkas SK.', 'Diubah: berkas SK.', 'Diubah: nomor SK.'],
            $p->riwayat()->where('kejadian', 'sk_diubah')->orderBy('id')->pluck('catatan')->all());

        $atur = app(AturPengingatSk::class);
        $p = $atur->handle($this->admin, $p, false);
        $this->assertSame($this->admin->id, $p->pengingat_sk_dimatikan_oleh);
        $p = $atur->handle($this->admin, $p, true);
        $this->assertNull($p->pengingat_sk_dimatikan_pada);
        $this->assertSame(['dibuat', 'diajukan', 'disetujui', 'sk_diubah', 'sk_diubah', 'sk_diubah', 'sk_diubah', 'pengingat_dimatikan', 'pengingat_dinyalakan'], $this->kejadian($p));
        $this->assertSame(['perubahan' => ['Pengingat SK hanya untuk perubahan yang sudah disetujui.']],
            $this->galat(fn () => $atur->handle($this->admin, $this->buat(), false)));
    }

    public function test_kesesuaian_of_an_spt_follows_an_approved_move_and_dampak_reports_it(): void
    {
        $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban1->id, 'pkpt_item_id' => $this->a->id,
            'tanggal_mulai' => '2026-04-06', 'tanggal_selesai' => '2026-04-08']);
        $this->assertContains('tidak_sesuai_pkpt', app(PenandaSpt::class)->untuk($spt));

        $c = PkptItem::factory()->create(['pkpt_id' => $this->pkpt->id, 'irban_id' => $this->irban1->id, 'tanggal_mulai' => '2026-08-03', 'tanggal_selesai' => '2026-08-07']);
        $p = $this->buat();
        $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $this->a->id, 'tanggal_mulai' => '2026-04-01', 'tanggal_selesai' => '2026-04-10']);
        $this->op($p, $this->tambahData(['irban_id' => $this->irban1->id]));
        $this->op($p, ['aksi' => 'hapus', 'pkpt_item_id' => $this->b->id]);
        $this->op($p, ['aksi' => 'ubah', 'pkpt_item_id' => $c->id, 'irban_id' => $this->irban2->id]); // a move counts for both irban

        $dampak = app(PerubahanPkpt::class)->dampak($p);
        $this->assertSame([['nama' => 'Irban I', 'jumlah' => 4], ['nama' => 'Irban II', 'jumlah' => 1]], $dampak['irban']);
        $this->assertSame([['id' => $spt->id, 'nomor_lengkap' => $spt->nomor_lengkap, 'irban' => 'Irban I', 'sebelum' => false, 'sesudah' => true]], $dampak['spt']); // sesuai PKPT (butir 14: no label text)

        $p = $this->putuskan($this->ajukan($p), true);
        $this->assertNotContains('tidak_sesuai_pkpt', app(PenandaSpt::class)->untuk($spt->fresh()));
        // After approval the impact is read from data_lama, so the history keeps showing the flip.
        $flip = app(PerubahanPkpt::class)->dampak($p)['spt'][0];
        $this->assertSame([false, true], [$flip['sebelum'], $flip['sesudah']]);
    }
}
