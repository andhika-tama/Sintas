<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\DeleteKlasifikasi;
use App\Actions\MasterData\SaveKlasifikasi;
use App\Actions\Spt\CatatNomorManual;
use App\Enums\Role;
use App\Enums\SyaratSelesai;
use App\Livewire\Admin\Klasifikasi as Page;
use App\Livewire\BagianUmum\Register;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** Klasifikasi & Aturan (spec 1.2.1 Task 6, K7): add, edit (kode locked), deactivate/activate, delete only when unused, urutan. */
class KlasifikasiTest extends TestCase
{
    use RefreshDatabase;

    private const DIPAKAI = 'Klasifikasi sudah dipakai SPT; tidak bisa dihapus.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function page()
    {
        return Livewire::actingAs(User::factory()->admin()->create())->test(Page::class);
    }

    private function k(string $kode): Klasifikasi
    {
        return Klasifikasi::where('kode', $kode)->firstOrFail();
    }

    private function baru(array $o = []): Klasifikasi
    {
        return app(SaveKlasifikasi::class)->handle(null, $o + ['kode' => 'AUDIT_KINERJA', 'nama' => 'AUDIT KINERJA', 'wajibItemPkpt' => false,
            'syaratSelesai' => 'konfirmasi', 'tampilPublik' => false]);
    }

    public function test_seeded_exactly_per_prd_9_4_with_urutan_by_id_and_active(): void
    {
        $rows = Klasifikasi::orderBy('id')->get()
            ->map(fn ($k) => [$k->kode, $k->nama, $k->wajib_item_pkpt, $k->syarat_selesai->value, $k->tampil_publik, $k->aktif, $k->urutan])->all();
        $this->assertSame([
            ['REGULER', 'REGULER', true, 'lhp_lengkap', true, true, 1],
            ['KHUSUS', 'KHUSUS', false, 'lhp_lengkap', false, true, 2],
            ['DIKLAT', 'DIKLAT', false, 'konfirmasi', false, true, 3],
            ['LUAR_DAERAH', 'LUAR DAERAH', false, 'laporan_opsional', false, true, 4],
            ['LAIN_LAIN', 'LAIN-LAIN', false, 'dokumen_opsional', false, true, 5],
        ], $rows);
    }

    public function test_migrasi_isi_mengisi_urutan_kosong_dan_idempoten(): void
    {
        // isi() is the data half of the migration (no DDL, so safe inside RefreshDatabase on MySQL).
        $migrasi = require database_path('migrations/2026_12_21_000001_tambah_aktif_urutan_ke_klasifikasi.php');
        DB::table('klasifikasi')->where('kode', 'DIKLAT')->update(['urutan' => 0]);
        $migrasi->isi();
        $migrasi->isi();
        $this->assertSame(['REGULER' => 1, 'KHUSUS' => 2, 'LUAR_DAERAH' => 4, 'LAIN_LAIN' => 5, 'DIKLAT' => 6], Klasifikasi::urut()->pluck('urutan', 'kode')->all());
    }

    public function test_label_and_scopes(): void
    {
        $this->k('DIKLAT')->update(['aktif' => false]);
        $this->k('LAIN_LAIN')->update(['urutan' => 0]);
        $this->assertSame('DIKLAT (nonaktif)', $this->k('DIKLAT')->label());
        $this->assertSame('KHUSUS', $this->k('KHUSUS')->label());
        $this->assertSame(['LAIN_LAIN', 'REGULER', 'KHUSUS', 'LUAR_DAERAH'], Klasifikasi::aktif()->urut()->pluck('kode')->all());
    }

    public function test_enum_labels(): void
    {
        $this->assertSame('Cover LHP + nomor + tanggal LHP', SyaratSelesai::LhpLengkap->label());
        $this->assertSame('Dokumen opsional', SyaratSelesai::DokumenOpsional->label());
    }

    public function test_only_admin_can_open(): void
    {
        $this->get('/admin/klasifikasi')->assertRedirect('/masuk');
        $this->actingAs(User::factory()->irban()->create())->get('/admin/klasifikasi')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/klasifikasi')->assertOk()
            ->assertSee('LUAR DAERAH')->assertSee('Laporan perjalanan opsional')->assertSee('Tambah klasifikasi');
    }

    public function test_tambah_dengan_urutan_bawaan_maks_plus_satu(): void
    {
        $this->k('LAIN_LAIN')->update(['urutan' => 9]);
        $this->page()->call('create')->assertSet('open', true)->assertSet('editingId', null)->assertSet('urutan', '10')
            ->set('kode', 'AUDIT_KINERJA')->set('nama', 'Audit Kinerja')->set('syaratSelesai', 'konfirmasi')->set('tampilPublik', true)->set('warna', '#c0ffee')
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertDispatched('toast', type: 'success', message: 'Klasifikasi Audit Kinerja disimpan.');

        $k = $this->k('AUDIT_KINERJA');
        $this->assertSame(['Audit Kinerja', false, SyaratSelesai::Konfirmasi, true, 'C0FFEE', true, 10],
            [$k->nama, $k->wajib_item_pkpt, $k->syarat_selesai, $k->tampil_publik, $k->warna, $k->aktif, $k->urutan]);

        // The action without urutan appends too; an explicit urutan is kept.
        $this->assertSame(11, $this->baru(['kode' => 'X1', 'nama' => 'X1'])->urutan);
        $this->assertSame(3, $this->baru(['kode' => 'X2', 'nama' => 'X2', 'urutan' => 3])->urutan);
    }

    public function test_ubah_tidak_mengubah_kode(): void
    {
        $k = $this->k('KHUSUS');
        $this->page()->call('edit', $k->id)->assertSet('open', true)->assertSet('nama', 'KHUSUS')->assertSet('urutan', '2')->assertSee('Kode tidak bisa diubah')
            ->set('kode', 'LAIN')->set('nama', 'KHUSUS BARU')->set('urutan', '7')
            ->set('wajibItemPkpt', true)->set('syaratSelesai', 'konfirmasi')->set('tampilPublik', true)
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertDispatched('toast');

        $k->refresh();
        $this->assertSame(['KHUSUS', 'KHUSUS BARU', 7, true, true, SyaratSelesai::Konfirmasi], [$k->kode, $k->nama, $k->urutan, $k->wajib_item_pkpt, $k->tampil_publik, $k->syarat_selesai]);

        app(SaveKlasifikasi::class)->handle($k, ['kode' => 'ZZZ', 'nama' => 'KHUSUS', 'wajibItemPkpt' => false, 'syaratSelesai' => 'lhp_lengkap', 'tampilPublik' => false]);
        $this->assertSame(['KHUSUS', 'KHUSUS', 7], [$k->fresh()->kode, $k->fresh()->nama, $k->fresh()->urutan]); // no urutan sent: kept
    }

    public function test_kode_nama_urutan_tidak_valid_ditolak(): void
    {
        $page = $this->page()->call('create')->set('nama', 'Baru')->set('syaratSelesai', 'konfirmasi');
        foreach (['', 'abc', 'A-B', 'A B', str_repeat('A', 31), 'REGULER'] as $salah) {
            $page->set('kode', $salah)->call('save')->assertHasErrors('kode');
        }
        $page->set('kode', 'BARU');
        foreach (['', 'reguler', ' Luar daerah ', str_repeat('a', 101)] as $salah) {
            $page->set('nama', $salah)->call('save')->assertHasErrors('nama');
        }
        $page->set('nama', 'Baru');
        foreach (['0', '1000', 'x', '1.5'] as $salah) {
            $page->set('urutan', $salah)->call('save')->assertHasErrors('urutan');
        }
        $page->set('urutan', '6')->set('syaratSelesai', 'bebas')->call('save')->assertHasErrors('syaratSelesai');
        $this->assertSame(5, Klasifikasi::count());

        $page->set('syaratSelesai', 'konfirmasi')->set('kode', str_repeat('A', 30))->call('save')->assertHasNoErrors();
        $this->assertSame(6, Klasifikasi::count());

        // The action validates on its own too.
        $this->assertThrows(fn () => $this->baru(['kode' => 'KHUSUS']), ValidationException::class);
        $this->assertThrows(fn () => $this->baru(['nama' => 'khusus']), ValidationException::class);
    }

    public function test_nama_unik_tanpa_memandang_huruf_saat_ubah(): void
    {
        $k = $this->k('KHUSUS');
        $page = $this->page()->call('edit', $k->id)->set('nama', 'Reguler')->call('save')->assertHasErrors('nama');
        $this->assertSame('KHUSUS', $k->fresh()->nama);
        $page->set('nama', 'Khusus')->call('save')->assertHasNoErrors(); // its own name in another case is fine
        $this->assertSame('Khusus', $k->fresh()->nama);
    }

    public function test_nonaktifkan_dan_aktifkan(): void
    {
        $k = $this->k('DIKLAT');
        $page = $this->page()->call('toggleAktif', $k->id)->assertDispatched('toast', type: 'success', message: 'Klasifikasi DIKLAT dinonaktifkan.');
        $this->assertFalse($k->fresh()->aktif);
        $page->assertSee('DIKLAT (nonaktif)')->assertSee('Nonaktif')->assertSee('Aktifkan');
        $page->call('toggleAktif', $k->id)->assertDispatched('toast', type: 'success', message: 'Klasifikasi DIKLAT diaktifkan.');
        $this->assertTrue($k->fresh()->aktif);
    }

    public function test_hapus_klasifikasi_yang_belum_dipakai(): void
    {
        $k = $this->baru();
        $this->page()->call('confirmDelete', $k->id)->assertSet('deleteOpen', true)->assertSet('deletingId', $k->id)->assertSee('AUDIT KINERJA')
            ->call('delete')->assertSet('deleteOpen', false)
            ->assertDispatched('toast', type: 'success', message: 'Klasifikasi AUDIT KINERJA dihapus.')->assertDispatched('fokus-ke', id: 'judul-klasifikasi');
        $this->assertNull(Klasifikasi::find($k->id));
    }

    /** Final review 2: an SPT lands between the check and the delete; the FK error becomes the same validation error, not a 500. */
    public function test_hapus_klasifikasi_yang_dipakai_di_antara_cek_dan_hapus_menjadi_galat_validasi(): void
    {
        $k = $this->baru();
        Klasifikasi::deleting(fn () => throw new QueryException('x', 'delete from t', [], new \PDOException('FK', 23000))); // what the DB raises when an SPT references the row

        $this->assertThrows(fn () => app(DeleteKlasifikasi::class)->handle($k), fn (ValidationException $e) => $e->errors() === ['delete' => [self::DIPAKAI]]);
        $this->assertNotNull(Klasifikasi::find($k->id));
    }

    /** Final review 3: "/" in nama would split the SPT number ({klasifikasi}). */
    public function test_nama_klasifikasi_tidak_boleh_memuat_garis_miring(): void
    {
        $this->assertThrows(fn () => $this->baru(['nama' => 'AUDIT/KINERJA']), fn (ValidationException $e) => $e->errors() === ['nama' => ['Nama klasifikasi tidak boleh memuat garis miring (/).']]);
        $this->assertNull(Klasifikasi::where('kode', 'AUDIT_KINERJA')->first());
    }

    /** Review Focus 4: a klasifikasi used only by a draf still counts as used (FK restrictOnDelete). */
    public function test_hapus_klasifikasi_yang_dipakai_walau_hanya_draf_ditolak(): void
    {
        $k = $this->baru();
        $spt = Spt::factory()->create(['klasifikasi_id' => $k->id]); // Draf, no number
        $this->assertNull($spt->nomor_urut);

        $this->page()->call('confirmDelete', $k->id)->call('delete')->assertDispatched('toast', type: 'error', message: self::DIPAKAI);
        $this->assertNotNull(Klasifikasi::find($k->id));
        $this->assertThrows(fn () => app(DeleteKlasifikasi::class)->handle($k), fn (ValidationException $e) => $e->errors() === ['delete' => [self::DIPAKAI]]);
        $this->assertNotNull(Klasifikasi::find($k->id));
    }

    public function test_tombol_hapus_nonaktif_dengan_alasan_bila_dipakai(): void
    {
        Spt::factory()->create(['klasifikasi_id' => $this->k('KHUSUS')->id]);
        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/klasifikasi')->assertOk()->getContent();
        $khusus = $this->k('KHUSUS')->id;
        $reguler = $this->k('REGULER')->id;

        $this->assertStringContainsString('aria-describedby="hapus-alasan-'.$khusus.'"', $html);
        $this->assertMatchesRegularExpression('~<button[^>]*aria-disabled="true"[^>]*aria-describedby="hapus-alasan-'.$khusus.'"~', $html);
        $this->assertMatchesRegularExpression('~id="hapus-alasan-'.$khusus.'"[^>]*>'.preg_quote(self::DIPAKAI, '~').'<~', $html);
        $this->assertStringNotContainsString('wire:click="confirmDelete('.$khusus.')"', $html);
        $this->assertStringContainsString('wire:click="confirmDelete('.$reguler.')"', $html);
    }

    public function test_daftar_mengikuti_urutan(): void
    {
        $this->k('LAIN_LAIN')->update(['urutan' => 0]);
        $this->page()->assertSeeInOrder(['LAIN-LAIN', 'REGULER', 'KHUSUS', 'DIKLAT', 'LUAR DAERAH']);
    }

    public function test_id_terkunci(): void
    {
        $page = $this->page();
        $this->assertThrows(fn () => $page->set('editingId', 1), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $page->set('deletingId', 1), CannotUpdateLockedPropertyException::class);
    }

    public function test_baris_yang_sudah_dihapus_di_tab_lain_menjadi_toast(): void
    {
        $k = $this->baru();
        $page = $this->page()->call('edit', $k->id)->set('nama', 'Ganti');
        $k->delete();
        $pesan = 'Klasifikasi tidak ditemukan; mungkin sudah dihapus.';
        $page->call('save')->assertDispatched('toast', type: 'error', message: $pesan)->assertSet('open', false);
        foreach (['edit', 'toggleAktif', 'confirmDelete'] as $aksi) {
            $this->page()->call($aksi, $k->id)->assertDispatched('toast', type: 'error', message: $pesan);
        }
        $b = $this->baru(['kode' => 'B', 'nama' => 'B']);
        $page = $this->page()->call('confirmDelete', $b->id);
        $b->delete();
        $page->call('delete')->assertDispatched('toast', type: 'error', message: $pesan)->assertSet('deleteOpen', false);
    }

    public function test_non_admin_cannot_open(): void
    {
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class)->assertForbidden();
    }

    public function test_demoted_admin_is_rejected_on_every_action_and_nothing_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $k = $this->baru();
        $demoted = function (string $method, ...$args) use ($admin, $k) {
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Page::class)->call('confirmDelete', $k->id)->call('edit', $k->id)->set('tampilPublik', true);
            $admin->role = Role::Irban;
            $c->call($method, ...$args)->assertForbidden();
        };
        foreach ([['create'], ['edit', $k->id], ['save'], ['toggleAktif', $k->id], ['confirmDelete', $k->id], ['delete']] as $panggil) {
            $demoted(...$panggil);
        }
        $k->refresh();
        $this->assertFalse($k->tampil_publik);
        $this->assertTrue($k->aktif);
    }

    public function test_title_is_escaped_once(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/klasifikasi')->getContent();
        $this->assertStringContainsString('>Klasifikasi &amp; Aturan</h1>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /** Spec 1.2.1 Task 6 step 3: an inactive klasifikasi is gone from manual numbering and the Register options; old SPT keep its name. */
    public function test_nonaktif_ditolak_catat_manual_dan_hilang_dari_opsi_register_tetapi_tampil_di_spt_lama(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $bu = User::factory()->create(['role' => Role::BagianUmum]);
        $irban = Irban::factory()->create(['kode_nomor' => 'INSPEKTORAT-II']);
        $diklat = $this->k('DIKLAT');
        $lama = Spt::factory()->diterima()->create(['irban_id' => $irban->id, 'klasifikasi_id' => $diklat->id]);
        $diklat->update(['aktif' => false]);
        $this->k('LAIN_LAIN')->update(['urutan' => 0]);

        $data = ['irban_id' => $irban->id, 'klasifikasi_id' => $diklat->id, 'kode_arsip_id' => KodeArsip::where('kode', '700')->value('id'),
            'uraian_untuk' => 'Pemeriksaan mendesak', 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-25', 'alasan' => 'Perintah mendadak dari Bupati'];
        $this->assertThrows(fn () => app(CatatNomorManual::class)->handle($bu, $data),
            fn (ValidationException $e) => $e->errors()['klasifikasi_id'] === ['Klasifikasi DIKLAT sudah nonaktif.']);

        $opsi = Livewire::actingAs($bu)->test(Register::class)->viewData('klasifikasiOptions');
        $this->assertSame(['LAIN-LAIN', 'REGULER', 'KHUSUS', 'LUAR DAERAH'], array_values($opsi));

        $this->actingAs($bu)->get(route('spt.show', $lama))->assertOk()->assertSee('DIKLAT');
    }
}
