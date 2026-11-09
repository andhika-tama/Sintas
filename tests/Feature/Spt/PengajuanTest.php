<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\SimpanSpt;
use App\Actions\Spt\ValidatePersonel;
use App\Enums\PeranTim;
use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\Tim;
use App\Models\User;
use App\Services\SaranUraian;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\IrbanSeeder;
use Database\Seeders\SptSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PengajuanTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $user;

    private Pegawai $ppj;

    private Pegawai $anggota;

    private PkptItem $item;

    private JenisPenugasan $jenis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));

        $this->irban = Irban::factory()->create(['nama' => 'Irban I']);
        $this->user = $this->irbanUser($this->irban);
        $this->ppj = Pegawai::factory()->create(['nama_bergelar' => 'Andi Saputra']);
        $this->anggota = Pegawai::factory()->create(['nama_bergelar' => 'Budi Santoso']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->ppj->id])
            ->anggota()->attach($this->anggota->id);
        $this->jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu LKjIP']);
        $this->item = PkptItem::factory()->create([
            'pkpt_id' => Pkpt::factory()->disahkan()->create(['tahun' => 2026])->id, 'irban_id' => $this->irban->id,
            'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20',
        ]);
    }

    private function irbanUser(Irban $irban): User
    {
        $u = User::factory()->irban()->create();
        $u->forceFill(['irban_id' => $irban->id])->save();

        return $u;
    }

    private function klas(string $kode): int
    {
        return Klasifikasi::where('kode', $kode)->value('id');
    }

    private function personel(): array
    {
        return [
            ['pegawai_id' => $this->ppj->id, 'peran' => 'ketua', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
            ['pegawai_id' => $this->anggota->id, 'peran' => 'anggota', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
        ];
    }

    /** A complete REGULER submission. */
    private function data(array $over = []): array
    {
        return array_merge([
            'klasifikasi_id' => $this->klas('REGULER'), 'kode_arsip_id' => KodeArsip::where('kode', '700')->value('id'), 'pkpt_item_id' => $this->item->id, 'non_pkpt' => false,
            'objek_ids' => [Objek::factory()->create()->id], 'periode_diperiksa' => 'Tahun Anggaran 2025',
            'uraian_untuk' => 'Melaksanakan Reviu LKjIP.', 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-10',
            'ppj_pegawai_id' => $this->ppj->id, 'catatan_bagian_umum' => null, 'diinput_oleh' => $this->anggota->id,
            'personel' => $this->personel(),
        ], $over);
    }

    private function simpan(array $over = [], string $mode = 'ajukan', ?Spt $spt = null, ?User $user = null, ?UploadedFile $f = null, bool $hapus = false): Spt
    {
        return app(SimpanSpt::class)->handle($user ?? $this->user, $spt, $this->data($over), $mode, $f, $hapus);
    }

    private function assertRejected(callable $fn, string $key, string $message): void
    {
        try {
            $fn();
            $this->fail("Expected a validation error on {$key}.");
        } catch (ValidationException $e) {
            $this->assertContains($message, $e->errors()[$key] ?? [], json_encode($e->errors()));
        }
    }

    public function test_complete_ajukan_goes_to_menunggu_nomor_with_jenis_from_item(): void
    {
        $spt = $this->simpan(['jenis_penugasan_id' => null]);

        $this->assertSame(StatusPengajuan::MenungguNomor, $spt->status_pengajuan);
        $this->assertNotNull($spt->diajukan_pada);
        $this->assertSame($this->jenis->id, $spt->jenis_penugasan_id);
        $this->assertSame($this->irban->id, $spt->irban_id);
        $this->assertSame($this->user->id, $spt->dibuat_oleh);
        $this->assertCount(1, $spt->objek);
        $this->assertSame(['dibuat', 'diajukan'], $spt->riwayat()->orderBy('id')->pluck('kejadian')->all());
        $this->assertSame($this->anggota->id, $spt->riwayat()->orderBy('id')->first()->diinput_oleh);
        $this->assertSame($this->anggota->id, $spt->diinput_oleh);
    }

    /** 1.2 Task 2 (K4): the Irban picks the kode arsip when submitting; a draft may leave it empty, an unknown id counts as empty. */
    public function test_kode_arsip_wajib_saat_mengajukan(): void
    {
        $this->assertRejected(fn () => $this->simpan(['kode_arsip_id' => null]), 'kode_arsip_id', 'Pilih kode arsip.');
        $this->assertRejected(fn () => $this->simpan(['kode_arsip_id' => 999999]), 'kode_arsip_id', 'Pilih kode arsip.');
        $this->assertSame(0, Spt::count());

        $draf = $this->simpan(['kode_arsip_id' => 999999], 'draf');
        $this->assertNull($draf->kode_arsip_id);
        $delapan = KodeArsip::where('kode', '800')->value('id');
        $this->assertSame($delapan, $this->simpan(['kode_arsip_id' => (string) $delapan], 'ajukan', $draf)->kode_arsip_id);
    }

    public function test_draf_needs_only_diinput_oleh_and_klasifikasi(): void
    {
        $spt = app(SimpanSpt::class)->handle($this->user, null, [
            'klasifikasi_id' => $this->klas('REGULER'), 'diinput_oleh' => $this->anggota->id,
        ], 'draf');

        $this->assertSame(StatusPengajuan::Draf, $spt->status_pengajuan);
        $this->assertNull($spt->diajukan_pada);
        $this->assertSame(['dibuat'], $spt->riwayat->pluck('kejadian')->all());

        $this->assertRejected(fn () => app(SimpanSpt::class)->handle($this->user, null, ['klasifikasi_id' => $this->klas('REGULER')], 'draf'),
            'diinput_oleh', 'Pilih nama penginput terlebih dahulu');
        $this->assertRejected(fn () => app(SimpanSpt::class)->handle($this->user, null, ['diinput_oleh' => $this->anggota->id], 'draf'),
            'klasifikasi_id', 'Pilih klasifikasi.');
        $this->assertRejected(fn () => app(SimpanSpt::class)->handle($this->user, null,
            ['klasifikasi_id' => $this->klas('REGULER'), 'diinput_oleh' => Pegawai::factory()->create()->id], 'draf'),
            'diinput_oleh', 'Penginput harus PPJ atau anggota aktif Irban ini tahun 2026.');
    }

    public function test_required_fields_on_ajukan(): void
    {
        $cases = [
            ['pkpt_item_id', 'Pilih item PKPT untuk SPT Reguler.', ['pkpt_item_id' => null]],
            ['objek_ids', 'Pilih minimal satu objek.', ['objek_ids' => []]],
            ['periode_diperiksa', 'Isi periode yang diperiksa.', ['periode_diperiksa' => ' ']],
            ['periode_diperiksa', 'Periode maksimal 255 karakter.', ['periode_diperiksa' => str_repeat('a', 256)]],
            ['uraian_untuk', 'Isi uraian.', ['uraian_untuk' => '']],
            ['uraian_untuk', 'Uraian maksimal 1000 karakter.', ['uraian_untuk' => str_repeat('a', 1001)]],
            ['tanggal_mulai', 'Isi tanggal mulai.', ['tanggal_mulai' => null]],
            ['tanggal_selesai', 'Isi tanggal selesai.', ['tanggal_selesai' => null]],
            ['tanggal_selesai', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', ['tanggal_mulai' => '2026-03-10', 'tanggal_selesai' => '2026-03-09']],
            ['ppj_pegawai_id', 'Pilih PPJ.', ['ppj_pegawai_id' => null]],
            ['catatan_bagian_umum', 'Catatan maksimal 1000 karakter.', ['catatan_bagian_umum' => str_repeat('a', 1001)]],
        ];
        foreach ($cases as [$key, $msg, $over]) {
            $this->assertRejected(fn () => $this->simpan($over), $key, $msg);
        }
        $this->assertSame(0, Spt::count());

        $this->assertRejected(fn () => $this->simpan(['ppj_pegawai_id' => Pegawai::factory()->create(['aktif' => false])->id]), 'ppj_pegawai_id', 'Pilih PPJ.');
        // Jenis is required manually when no item is chosen.
        $this->assertRejected(fn () => $this->simpan(['klasifikasi_id' => $this->klas('KHUSUS'), 'pkpt_item_id' => null, 'jenis_penugasan_id' => null]),
            'jenis_penugasan_id', 'Pilih jenis penugasan.');
    }

    public function test_non_pkpt_only_for_klasifikasi_without_wajib_item(): void
    {
        $this->assertRejected(fn () => $this->simpan(['pkpt_item_id' => null, 'non_pkpt' => true]),
            'pkpt_item_id', 'Pilih item PKPT untuk SPT Reguler.');

        $khusus = $this->simpan(['klasifikasi_id' => $this->klas('KHUSUS'), 'pkpt_item_id' => null, 'non_pkpt' => true, 'jenis_penugasan_id' => $this->jenis->id]);
        $this->assertNull($khusus->pkpt_item_id);
        $this->assertSame($this->jenis->id, $khusus->jenis_penugasan_id);
        $this->assertSame(StatusPengajuan::MenungguNomor, $khusus->status_pengajuan);
    }

    public function test_item_of_other_irban_rejected(): void
    {
        $lain = PkptItem::factory()->create(['pkpt_id' => $this->item->pkpt_id, 'irban_id' => Irban::factory()->create()->id]);

        $this->assertRejected(fn () => $this->simpan(['pkpt_item_id' => $lain->id]), 'pkpt_item_id', 'Item PKPT tidak valid untuk Irban ini.');
        $this->assertRejected(fn () => $this->simpan(['pkpt_item_id' => $lain->id], 'draf'), 'pkpt_item_id', 'Item PKPT tidak valid untuk Irban ini.');
    }

    public function test_pkpt_draf_warns_and_blocks_by_setting(): void
    {
        $this->item->pkpt->forceFill(['status' => 'draf'])->save();
        $this->assertContains('PKPT belum disahkan.', app(SimpanSpt::class)->peringatan($this->data(), $this->irban->id));

        $action = app(SimpanSpt::class);
        $spt = $action->handle($this->user, null, $this->data(), 'ajukan');
        $this->assertSame(StatusPengajuan::MenungguNomor, $spt->status_pengajuan);
        $this->assertContains('PKPT belum disahkan.', $action->peringatan);

        app(Settings::class)->set('pkpt.draf_memblokir', true);
        $this->assertRejected(fn () => $this->simpan(), 'pkpt_item_id', 'PKPT belum disahkan.');
        $this->assertSame(StatusPengajuan::Draf, $this->simpan([], 'draf')->status_pengajuan);
    }

    public function test_out_of_pkpt_warning_text_and_never_blocks(): void
    {
        $data = $this->data(['tanggal_mulai' => '2026-03-01', 'tanggal_selesai' => '2026-03-10']);
        $this->assertSame(['Di luar jadwal PKPT (2–20 Maret 2026). SPT akan ditandai Tidak Sesuai PKPT.'], app(SimpanSpt::class)->peringatan($data, $this->irban->id));
        $this->assertSame(StatusPengajuan::MenungguNomor, $this->simpan($data)->status_pengajuan);

        $this->assertSame([], app(SimpanSpt::class)->peringatan($this->data(), $this->irban->id));
        $this->assertSame([], app(SimpanSpt::class)->peringatan($this->data(['pkpt_item_id' => null, 'tanggal_mulai' => null]), $this->irban->id));
    }

    public function test_peringatan_ignores_item_of_another_irban(): void
    {
        $this->item->pkpt->forceFill(['status' => 'draf'])->save();
        $lain = PkptItem::factory()->create(['pkpt_id' => $this->item->pkpt_id, 'irban_id' => Irban::factory()->create()->id,
            'tanggal_mulai' => '2026-01-05', 'tanggal_selesai' => '2026-01-09']);

        $this->assertSame([], app(SimpanSpt::class)->peringatan($this->data(['pkpt_item_id' => $lain->id]), $this->irban->id));
        $this->assertNotSame([], app(SimpanSpt::class)->peringatan($this->data(['pkpt_item_id' => $lain->id]), $lain->irban_id));
    }

    public function test_cek_personel_summarises_the_rules_used_by_handle(): void
    {
        $cek = app(ValidatePersonel::class);
        $row = fn ($id, $peran, $ikut = true) => ['pegawai_id' => $id, 'peran' => $peran, 'ikut' => $ikut];

        $this->assertSame(['ketua' => true, 'supervisor' => true, 'duplikat' => true, 'jumlah' => 2],
            $cek->cek([$row(1, 'ketua'), $row(2, 'anggota'), $row(3, 'ketua', false)]));
        $this->assertSame(['ketua' => false, 'supervisor' => false, 'duplikat' => false, 'jumlah' => 4],
            $cek->cek([$row(1, 'supervisor_dalnis'), $row(2, 'supervisor_dalnis'), $row(3, 'anggota'), $row(3, 'anggota')]));
        $this->assertSame(['ketua' => false, 'supervisor' => true, 'duplikat' => true, 'jumlah' => 0], $cek->cek([]));
    }

    public function test_transitions(): void
    {
        $spt = $this->simpan();
        $this->assertRejected(fn () => $this->simpan([], 'draf', $spt), 'spt', 'SPT ini sudah dikirim dan tidak dapat diubah.');
        $this->assertRejected(fn () => $this->simpan([], 'ajukan', $spt), 'spt', 'SPT ini sudah dikirim dan tidak dapat diubah.');

        foreach ([StatusPengajuan::Diterima, StatusPengajuan::Ditolak, StatusPengajuan::Dibatalkan] as $s) {
            $x = Spt::factory()->for($this->irban)->create(['status_pengajuan' => $s]);
            $this->assertRejected(fn () => $this->simpan([], 'draf', $x), 'spt', 'SPT ini sudah dikirim dan tidak dapat diubah.');
        }

        // perlu_revisi: saving keeps the status, ajukan resubmits.
        $rev = Spt::factory()->for($this->irban)->perluRevisi()->create(['dibuat_oleh' => $this->user->id]);
        $this->assertSame(StatusPengajuan::PerluRevisi, $this->simpan(['uraian_untuk' => 'Baru'], 'draf', $rev)->status_pengajuan);
        $rev = $this->simpan([], 'ajukan', $rev);
        $this->assertSame(StatusPengajuan::MenungguNomor, $rev->status_pengajuan);
        $this->assertSame(['diubah', 'diajukan'], $rev->riwayat()->orderBy('id')->pluck('kejadian')->all());
        $this->assertSame($this->anggota->id, $rev->riwayat()->orderBy('id')->first()->diinput_oleh);
    }

    public function test_other_irban_cannot_touch_spt(): void
    {
        $spt = $this->simpan([], 'draf');
        $lain = $this->irbanUser(Irban::factory()->create());

        $this->expectException(AuthorizationException::class);
        $this->simpan([], 'draf', $spt, $lain);
    }

    public function test_only_irban_accounts_can_create(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->simpan([], 'draf', null, User::factory()->bagianUmum()->create());
    }

    public function test_personel_rules_and_messages(): void
    {
        $p = fn (Pegawai $g, string $peran) => ['pegawai_id' => $g->id, 'peran' => $peran, 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true];
        $c = Pegawai::factory()->create(['nama_bergelar' => 'Citra Dewi']);

        $this->assertRejected(fn () => $this->simpan(['personel' => []]), 'personel', 'Pilih minimal satu personel.');
        $this->assertRejected(fn () => $this->simpan(['personel' => [['ikut' => false] + $p($this->ppj, 'ketua')]]), 'personel', 'Pilih minimal satu personel.');
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'anggota')]]), 'personel', 'Tetapkan tepat satu Ketua Tim');
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'ketua'), $p($this->anggota, 'ketua')]]), 'personel', 'Tetapkan tepat satu Ketua Tim');
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'ketua'), $p($this->anggota, 'supervisor_dalnis'), $p($c, 'supervisor_dalnis')]]),
            'personel', 'Maksimal satu Supervisor/Dalnis.');
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'ketua'), $p($this->ppj, 'anggota')]]),
            'personel', 'Andi Saputra tercantum lebih dari sekali.');
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'ketua'), $p(Pegawai::factory()->create(['nama_bergelar' => 'Eko Nonaktif', 'aktif' => false]), 'anggota')]]),
            'personel', 'Eko Nonaktif tidak aktif dan tidak dapat ditambahkan.');
        // draf is allowed to be incomplete, but never to hold duplicates.
        $this->assertSame(StatusPengajuan::Draf, $this->simpan(['personel' => []], 'draf')->status_pengajuan);
        $this->assertRejected(fn () => $this->simpan(['personel' => [$p($this->ppj, 'ketua'), $p($this->ppj, 'anggota')]], 'draf'),
            'personel', 'Andi Saputra tercantum lebih dari sekali.');
    }

    public function test_inactive_pegawai_already_on_the_spt_may_stay(): void
    {
        $spt = $this->simpan([], 'draf');
        $this->assertCount(2, $spt->personel);
        $this->anggota->update(['aktif' => false]);

        $spt = $this->simpan(['diinput_oleh' => $this->ppj->id], 'ajukan', $spt);
        $this->assertCount(2, $spt->fresh()->personel);
    }

    public function test_prd_14_1_two_teams_uncheck_one_member_add_from_other_irban(): void
    {
        $a = Pegawai::factory()->create();
        $b = Pegawai::factory()->create();
        $c = Pegawai::factory()->create();
        $luar = Pegawai::factory()->create(['nama_bergelar' => 'Dari Irban II']);
        $t1 = Tim::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026]);
        $t2 = Tim::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026]);

        $spt = $this->simpan(['personel' => [
            ['pegawai_id' => $a->id, 'peran' => 'supervisor_dalnis', 'sumber' => 'tim', 'tim_id' => $t1->id, 'ikut' => true],
            ['pegawai_id' => $b->id, 'peran' => 'ketua', 'sumber' => 'tim', 'tim_id' => $t1->id, 'ikut' => true],
            ['pegawai_id' => $c->id, 'peran' => 'anggota', 'sumber' => 'tim', 'tim_id' => $t2->id, 'ikut' => false],
            ['pegawai_id' => $this->anggota->id, 'peran' => 'anggota', 'sumber' => 'tim', 'tim_id' => $t2->id, 'ikut' => true],
            ['pegawai_id' => $luar->id, 'peran' => 'anggota', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
        ]]);

        $rows = $spt->personel()->get();
        $this->assertSame([1, 2, 3, 4], $rows->pluck('urutan')->all());
        $this->assertSame([$a->id, $b->id, $this->anggota->id, $luar->id], $rows->pluck('pegawai_id')->all());
        $this->assertSame([PeranTim::SupervisorDalnis, PeranTim::Ketua, PeranTim::Anggota, PeranTim::Anggota], $rows->pluck('peran')->all());
        $this->assertSame(['tim', 'tim', 'tim', 'tambahan'], $rows->pluck('sumber')->all());
        $this->assertSame([$t1->id, $t1->id, $t2->id, null], $rows->pluck('tim_id')->all());
    }

    public function test_resave_replaces_personel_and_objek(): void
    {
        $spt = $this->simpan([], 'draf');
        $objek = Objek::factory()->create();
        $spt = $this->simpan(['objek_ids' => [$objek->id], 'personel' => [$this->personel()[0]]], 'draf', $spt);

        $this->assertSame([$objek->id], $spt->objek()->pluck('objek.id')->all());
        $this->assertCount(1, $spt->personel);
        $this->assertSame(1, Spt::count());
    }

    public function test_saran_uraian(): void
    {
        $s = new SaranUraian;
        $this->assertSame('Melaksanakan Reviu LKjIP pada Dinas PUPR.', $s->saran('Reviu LKjIP', ['Dinas PUPR'], null));
        $this->assertSame('Melaksanakan Reviu LKjIP pada Dinas PUPR dan Dinas Kesehatan untuk Tahun Anggaran 2025.',
            $s->saran('Reviu LKjIP', ['Dinas PUPR', 'Dinas Kesehatan'], 'Tahun Anggaran 2025'));
        $this->assertSame('Melaksanakan Reviu LKjIP pada Dinas PUPR, Dinas Kesehatan dan Dinas Pendidikan untuk Tahun Anggaran 2025.',
            $s->saran('Reviu LKjIP', ['Dinas PUPR', 'Dinas Kesehatan', 'Dinas Pendidikan'], 'Tahun Anggaran 2025'));
        $this->assertSame('Melaksanakan Reviu LKjIP pada A, B dan C.', $s->saran('Reviu LKjIP', ['A', 'B', 'C'], ' '));
    }

    public function test_lampiran_pdf_stored_privately_with_sha256_and_replace_remove(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('draf.pdf', "%PDF-1.4\nisi");
        $spt = $this->simpan([], 'ajukan', null, null, $pdf);
        $dok = $spt->dokumen()->firstOrFail();

        $this->assertSame('draf', $dok->jenis);
        $this->assertSame('draf.pdf', $dok->nama_asli);
        $this->assertSame(hash('sha256', "%PDF-1.4\nisi"), $dok->sha256);
        $this->assertSame($this->user->id, $dok->diunggah_oleh);
        $this->assertSame($this->anggota->id, $dok->diinput_oleh);
        Storage::disk('local')->assertExists($dok->path);

        $spt = $this->simpan([], 'draf', null, null, UploadedFile::fake()->createWithContent('baru.pdf', "%PDF-1.4\nbaru"));
        $lama = $spt->dokumen()->first();
        $spt = $this->simpan([], 'draf', $spt, null, UploadedFile::fake()->createWithContent('lagi.pdf', "%PDF-1.4\nlagi"));
        $this->assertCount(1, $spt->dokumen()->get());
        $this->assertSame('lagi.pdf', $spt->dokumen()->first()->nama_asli);
        Storage::disk('local')->assertMissing($lama->path);

        $path = $spt->dokumen()->first()->path;
        $spt = $this->simpan([], 'draf', $spt, null, null, true);
        $this->assertCount(0, $spt->dokumen()->get());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_lampiran_rejects_non_pdf_and_oversize(): void
    {
        $pesan = 'Lampiran harus PDF dan maksimal 5 MB.';
        $this->assertRejected(fn () => $this->simpan([], 'ajukan', null, null, UploadedFile::fake()->create('a.docx', 10, 'application/msword')), 'lampiran', $pesan);
        $this->assertRejected(fn () => $this->simpan([], 'ajukan', null, null, UploadedFile::fake()->create('a.pdf', 6000, 'application/pdf')), 'lampiran', $pesan);
        $this->assertRejected(fn () => $this->simpan([], 'ajukan', null, null, UploadedFile::fake()->create('a.pdf', 10, 'text/plain')), 'lampiran', $pesan);
        $this->assertSame(0, Spt::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_policy_matrix(): void
    {
        $lain = $this->irbanUser(Irban::factory()->create());
        $bu = User::factory()->bagianUmum()->create();
        $pimpinan = User::factory()->pimpinan()->create();
        $admin = User::factory()->admin()->create();
        $draf = Spt::factory()->for($this->irban)->create();
        $menunggu = Spt::factory()->for($this->irban)->menungguNomor()->create();
        $revisi = Spt::factory()->for($this->irban)->perluRevisi()->create();
        $can = fn (User $u, string $a, $m = Spt::class) => Gate::forUser($u)->allows($a, $m);

        $this->assertTrue($can($this->user, 'view', $draf));
        $this->assertFalse($can($lain, 'view', $draf));
        $this->assertFalse($can($lain, 'view', $menunggu));
        foreach ([$bu, $pimpinan, $admin] as $u) {
            $this->assertFalse($can($u, 'view', $draf));
            $this->assertTrue($can($u, 'view', $menunggu));
        }
        $this->assertTrue($can($this->user, 'create'));
        foreach ([$bu, $pimpinan, $admin] as $u) {
            $this->assertFalse($can($u, 'create'));
        }
        $this->assertTrue($can($this->user, 'update', $draf));
        $this->assertTrue($can($this->user, 'update', $revisi));
        $this->assertFalse($can($this->user, 'update', $menunggu));
        $this->assertFalse($can($lain, 'update', $draf));
        $this->assertFalse($can($bu, 'update', $draf));
        $this->assertTrue($can($bu, 'decide', $menunggu));
        $this->assertFalse($can($bu, 'decide', $draf));
        $this->assertFalse($can($this->user, 'decide', $menunggu));
        $this->assertFalse($can($admin, 'decide', $menunggu));
        $this->assertTrue($can($bu, 'kelolaNomor'));
        $this->assertFalse($can($pimpinan, 'kelolaNomor'));
        $this->assertFalse($can($this->user, 'kelolaNomor'));
    }

    public function test_dokumen_download_authorization(): void
    {
        $spt = $this->simpan([], 'ajukan', null, null, UploadedFile::fake()->createWithContent('draf.pdf', "%PDF-1.4\nisi"));
        $dok = $spt->dokumen()->first();
        $url = route('spt.dokumen', [$spt, $dok]);

        $this->get($url)->assertRedirect(route('login'));

        $res = $this->actingAs($this->user)->get($url)->assertOk();
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $this->actingAs($this->user)->get($url.'?unduh=1')->headers->get('Content-Disposition'));

        $this->actingAs(User::factory()->bagianUmum()->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->pimpinan()->create())->get($url)->assertOk();
        $this->actingAs($this->irbanUser(Irban::factory()->create()))->get($url)->assertForbidden();

        $lain = Spt::factory()->for($this->irban)->menungguNomor()->create();
        $this->actingAs($this->user)->get(route('spt.dokumen', [$lain, $dok]))->assertNotFound();
    }

    public function test_bagian_umum_cannot_download_from_draf_spt(): void
    {
        $spt = $this->simpan([], 'draf', null, null, UploadedFile::fake()->createWithContent('draf.pdf', "%PDF-1.4\nisi"));
        $url = route('spt.dokumen', [$spt, $spt->dokumen()->first()]);

        $this->actingAs(User::factory()->bagianUmum()->create())->get($url)->assertForbidden();
        $this->actingAs($this->user)->get($url)->assertOk();
    }

    public function test_dev_seeder_creates_numbered_spt_in_each_status(): void
    {
        $this->seed(IrbanSeeder::class);
        $this->seed(UserSeeder::class);
        User::where('username', 'irban1')->first()->forceFill(['irban_id' => Irban::where('nama', 'Irban I')->value('id')])->save();
        $this->seed(SptSeeder::class);

        $s = Spt::all()->groupBy(fn ($x) => $x->status_pengajuan->value);
        $this->assertGreaterThanOrEqual(3, $s['menunggu_nomor']->count());
        foreach (['draf', 'perlu_revisi', 'diterima', 'dibatalkan'] as $k) {
            $this->assertNotEmpty($s[$k] ?? [], $k);
        }
        $nomor = Spt::whereNotNull('nomor_urut')->orderBy('nomor_urut')->pluck('nomor_urut')->all();
        $this->assertSame(range(1, count($nomor)), $nomor);
    }

    /** Locking reads of one PKPT row (`Pkpt::whereKey()->lockForUpdate()`) while $fn runs. */
    private function kunciPkpt(callable $fn): int
    {
        // Counts queries that really carry a lock flag: SQLite drops FOR UPDATE from the SQL, so the SQL text cannot tell a locking read from a plain find.
        $n = 0;
        $aktif = true;
        Pkpt::addGlobalScope('hitung-kunci', function ($builder) use (&$n, &$aktif) {
            if ($aktif && $builder->getQuery()->lock) {
                $n++;
            }
        });
        try {
            $fn();
        } finally {
            $aktif = false;
        }

        return $n;
    }

    /**
     * Fase 11.2 Task 6 butir 5: the PKPT row lock (K7 against a concurrent Perubahan approval) is taken only when the save links an item
     * the SPT row does not hold yet; a stale copy that still claims the item retries with the lock.
     */
    public function test_kunci_pkpt_hanya_bila_item_berubah(): void
    {
        $spt = null;
        $this->assertSame(1, $this->kunciPkpt(function () use (&$spt) {
            $spt = $this->simpan(mode: 'draf');
        }));
        $this->assertSame(0, $this->kunciPkpt(fn () => $this->simpan(['uraian_untuk' => 'Diubah.'], 'draf', $spt)));
        $this->assertSame('Diubah.', $spt->fresh()->uraian_untuk);

        $lain = PkptItem::factory()->create(['pkpt_id' => $this->item->pkpt_id, 'irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->jenis->id]);
        $this->assertSame(1, $this->kunciPkpt(fn () => $this->simpan(['pkpt_item_id' => $lain->id], 'draf', $spt->fresh())));
        $this->assertSame(0, $this->kunciPkpt(fn () => $this->simpan(['pkpt_item_id' => null, 'klasifikasi_id' => $this->klas('KHUSUS')], 'draf', $spt->fresh())));

        // Another tab unlinked the item after this copy was read: the save retries and takes the lock first.
        $basi = $spt->fresh()->forceFill(['pkpt_item_id' => $this->item->id]); // what this tab still holds; the row has no item
        $this->assertSame(1, $this->kunciPkpt(fn () => $this->simpan(['pkpt_item_id' => $this->item->id], 'draf', $basi)));
        $this->assertSame($this->item->id, $spt->fresh()->pkpt_item_id);
    }
}
