<?php

namespace Tests\Feature\Spt;

use App\Enums\KejadianSpt;
use App\Enums\PeranTim;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Livewire\Irban\SptForm;
use App\Livewire\Irban\SptSaya;
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
use App\Models\TimAnggota;
use App\Models\User;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\PdfUji;
use Tests\TestCase;

class SptFormTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $user;

    private Pegawai $ppj;

    private Pegawai $budi;

    private Pegawai $citra;

    private Tim $tim1;

    private Tim $tim2;

    private PkptItem $item;

    private Objek $objek;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:42', 'Asia/Jakarta'));

        $this->irban = Irban::factory()->create(['nama' => 'Irban I']);
        $this->user = $this->irbanUser($this->irban);
        $this->ppj = Pegawai::factory()->create(['nama_bergelar' => 'Andi Saputra', 'nip' => '198001012005011001']);
        $this->budi = Pegawai::factory()->create(['nama_bergelar' => 'Budi Santoso', 'nip' => '199002022015021012']);
        $this->citra = Pegawai::factory()->create(['nama_bergelar' => 'Citra Lestari', 'nip' => '199103032016032013']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->ppj->id])
            ->anggota()->attach([$this->budi->id, $this->citra->id]);

        $this->tim1 = $this->tim('Tim 1', [[$this->ppj, 'supervisor_dalnis'], [$this->budi, 'ketua']]);
        $this->tim2 = $this->tim('Tim 2', [[$this->budi, 'anggota'], [$this->citra, 'anggota']]);

        $jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu LKjIP']);
        $this->item = PkptItem::factory()->create([
            'pkpt_id' => Pkpt::factory()->disahkan()->create(['tahun' => 2026])->id, 'irban_id' => $this->irban->id,
            'jenis_penugasan_id' => $jenis->id, 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20',
        ]);
        $this->objek = Objek::factory()->create(['nama' => 'Dinas PUPR', 'jenis' => 'dinas_badan']);
    }

    private function irbanUser(Irban $irban): User
    {
        $u = User::factory()->irban()->create();
        $u->forceFill(['irban_id' => $irban->id])->save();

        return $u;
    }

    private function tim(string $nama, array $anggota): Tim
    {
        $tim = Tim::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'nama' => $nama]);
        foreach ($anggota as $i => [$p, $peran]) {
            TimAnggota::create(['tim_id' => $tim->id, 'pegawai_id' => $p->id, 'peran_default' => $peran, 'urutan' => $i + 1]);
        }

        return $tim;
    }

    private function klas(string $kode): int
    {
        return Klasifikasi::where('kode', $kode)->value('id');
    }

    private function form(?Spt $spt = null)
    {
        return Livewire::actingAs($this->user)->test(SptForm::class, $spt ? ['spt' => $spt] : []);
    }

    /** A complete REGULER form, ready to ajukan. */
    private function lengkap()
    {
        return $this->form()
            ->set('diinput_oleh', $this->budi->id)
            ->set('klasifikasi_id', $this->klas('REGULER'))
            ->set('kode_arsip_id', KodeArsip::where('kode', '700')->value('id'))
            ->set('pkpt_item_id', $this->item->id)
            ->set('objek_ids', [$this->objek->id])
            ->set('periode_diperiksa', 'Tahun Anggaran 2025')
            ->set('tanggal_mulai', '2026-03-02')
            ->set('tanggal_selesai', '2026-03-10')
            ->call('toggleTim', $this->tim1->id);
    }

    public function test_routes_render_for_irban_and_403_for_other_roles(): void
    {
        $this->actingAs($this->user)->get('/irban/spt/ajukan')->assertOk()->assertSee('Ajukan Nomor SPT')
            ->assertSee('Pilih Tim')->assertSee('Susunan Personel')->assertSee('Tambahkan anggota lainnya')
            ->assertSee('Simpan draf')->assertSee('Ajukan Nomor')->assertSee('Diinput oleh')
            ->assertSee('Perubahan belum disimpan. Tinggalkan halaman?');
        $this->actingAs($this->user)->get('/irban/spt')->assertOk()->assertSee('SPT Saya');

        foreach ([Role::Admin, Role::Pimpinan, Role::BagianUmum] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get('/irban/spt/ajukan')->assertForbidden();
            $this->actingAs($u)->get('/irban/spt')->assertForbidden();
            Livewire::actingAs($u)->test(SptForm::class)->assertForbidden();
            Livewire::actingAs($u)->test(SptSaya::class)->assertForbidden();
        }
    }

    public function test_nav_items(): void
    {
        $links = collect(Navigation::links(Role::Irban));
        $this->assertSame('document-text', $links->firstWhere('route', 'irban.spt')['icon']);
        $this->assertSame('SPT Saya', $links->firstWhere('route', 'irban.spt')['label']);
        $this->assertSame('plus-circle', $links->firstWhere('route', 'irban.spt.ajukan')['icon']);
        $this->assertSame('Ajukan Nomor SPT', $links->firstWhere('route', 'irban.spt.ajukan')['label']);
    }

    public function test_options_and_defaults(): void
    {
        $lain = Tim::factory()->create(['nama' => 'Tim Lain Irban']);
        $this->form()
            ->assertSet('ppj_pegawai_id', $this->ppj->id)
            ->assertSee('Tim 1 · 2 orang')->assertSee('Tim 2 · 2 orang')->assertDontSee($lain->nama)
            ->assertSee('REGULER')->assertSee('LUAR DAERAH')->assertSee('Reviu LKjIP');
    }

    public function test_team_check_appends_members_with_default_roles_and_dedupes(): void
    {
        $page = $this->form()->call('toggleTim', $this->tim1->id);
        $this->assertSame([[$this->ppj->id, 'supervisor_dalnis', 'tim', $this->tim1->id, true], [$this->budi->id, 'ketua', 'tim', $this->tim1->id, true]],
            array_map(fn ($r) => [$r['pegawai_id'], $r['peran'], $r['sumber'], $r['tim_id'], $r['ikut']], $page->get('personel')));

        $page->call('toggleTim', $this->tim2->id)
            ->assertDispatched('toast', type: 'info', message: 'Budi Santoso sudah ada dari Tim 1');
        $this->assertSame([$this->ppj->id, $this->budi->id, $this->citra->id], array_column($page->get('personel'), 'pegawai_id'));
        $page->assertSee('3 personel dipilih')->assertSee('1 Ketua Tim')->assertSee('Maks. 1 Supervisor/Dalnis')->assertSee('Tidak ada duplikat');

        // Unchecking a team removes the rows it added.
        $page->call('toggleTim', $this->tim2->id);
        $this->assertSame([$this->ppj->id, $this->budi->id], array_column($page->get('personel'), 'pegawai_id'));
    }

    public function test_other_irban_team_is_rejected(): void
    {
        $lain = Tim::factory()->create();
        $this->form()->call('toggleTim', $lain->id)->assertNotFound();
    }

    public function test_role_change_reorder_and_remove(): void
    {
        $page = $this->form()->call('toggleTim', $this->tim1->id)->call('toggleTim', $this->tim2->id);
        $page->set('personel.2.peran', 'ketua')->set('personel.1.peran', 'anggota');
        $this->assertSame(['supervisor_dalnis', 'anggota', 'ketua'], array_column($page->get('personel'), 'peran'));

        $page->call('pindah', $this->citra->id, 0)->assertSet('pengumuman', 'Citra Lestari dipindah ke posisi 1')
            ->assertDispatched('personel-dipindah');
        $this->assertSame([$this->citra->id, $this->ppj->id, $this->budi->id], array_column($page->get('personel'), 'pegawai_id'));
        $page->call('turun', $this->citra->id)->assertSet('pengumuman', 'Citra Lestari dipindah ke posisi 2');
        $page->call('naik', $this->budi->id)->assertSet('pengumuman', 'Budi Santoso dipindah ke posisi 2');
        $this->assertSame([$this->ppj->id, $this->budi->id, $this->citra->id], array_column($page->get('personel'), 'pegawai_id'));
        $page->call('naik', $this->ppj->id); // already first: no-op
        $this->assertSame($this->ppj->id, $page->get('personel')[0]['pegawai_id']);

        $page->call('hapus', $this->budi->id);
        $this->assertSame([$this->ppj->id, $this->citra->id], array_column($page->get('personel'), 'pegawai_id'));
    }

    public function test_add_other_pegawai_as_anggota_and_dedupe(): void
    {
        $lain = Pegawai::factory()->create(['nama_bergelar' => 'Dewi Kartika', 'jabatan' => 'Auditor Muda']);
        $nonaktif = Pegawai::factory()->create(['nama_bergelar' => 'Eko Nonaktif', 'aktif' => false]);
        $page = $this->form()->assertSee('Dewi Kartika')->assertSee('Auditor Muda')->assertDontSee('Eko Nonaktif')
            ->set('tambahId', $lain->id);
        $this->assertSame([[$lain->id, 'anggota', 'tambahan', null, true]],
            array_map(fn ($r) => [$r['pegawai_id'], $r['peran'], $r['sumber'], $r['tim_id'], $r['ikut']], $page->get('personel')));
        $page->assertSet('tambahId', null)->set('tambahId', $lain->id)
            ->assertDispatched('toast', type: 'info', message: 'Dewi Kartika sudah ada di Susunan Personel');
        $this->assertCount(1, $page->get('personel'));
        $page->set('tambahId', $nonaktif->id);
        $this->assertCount(1, $page->get('personel'));
    }

    public function test_saran_prefills_until_edited_and_gunakan_saran_restores(): void
    {
        $page = $this->lengkap();
        $saran = 'Melaksanakan Reviu LKjIP pada Dinas PUPR untuk Tahun Anggaran 2025.';
        $page->assertSet('uraian_untuk', $saran)->assertSet('jenis_penugasan_id', $this->item->jenis_penugasan_id);

        $page->set('uraian_untuk', 'Uraian saya sendiri.')->set('periode_diperiksa', 'Semester I 2026')
            ->assertSet('uraian_untuk', 'Uraian saya sendiri.')
            ->call('gunakanSaran')->assertSet('uraian_untuk', 'Melaksanakan Reviu LKjIP pada Dinas PUPR untuk Semester I 2026.');
    }

    public function test_non_pkpt_clears_item_and_is_only_allowed_without_wajib_item(): void
    {
        $page = $this->form()->set('klasifikasi_id', $this->klas('KHUSUS'))->set('pkpt_item_id', $this->item->id)
            ->set('non_pkpt', true)->assertSet('pkpt_item_id', null)->assertSee('Non-PKPT');
        $page->set('klasifikasi_id', $this->klas('REGULER'))->assertSet('non_pkpt', false);
    }

    public function test_warnings_and_durasi_rendered(): void
    {
        $this->item->pkpt->forceFill(['status' => 'draf'])->save();
        $this->form()->set('pkpt_item_id', $this->item->id)->set('tanggal_mulai', '2026-04-06')->set('tanggal_selesai', '2026-04-10')
            ->assertSee('PKPT belum disahkan.')
            ->assertSee('Di luar jadwal PKPT (2–20 Maret 2026). SPT akan ditandai Tidak Sesuai PKPT.')
            ->assertSee('Durasi: 5 hari');
    }

    public function test_validation_errors_show_inline_and_in_summary(): void
    {
        $this->form()->set('klasifikasi_id', $this->klas('REGULER'))->call('ajukan')
            ->assertHasErrors(['diinput_oleh', 'pkpt_item_id', 'objek_ids', 'personel'])
            ->assertSee('Periksa')->assertSee('Pilih nama penginput terlebih dahulu')->assertSee('Pilih item PKPT untuk SPT Reguler.')
            ->assertSee('Pilih minimal satu personel.')
            ->assertDispatched('gagal-simpan');
        $this->assertSame(0, Spt::count());

        $this->lengkap()->set('personel.1.peran', 'anggota')->call('ajukan')
            ->assertHasErrors('personel')->assertSee('Tetapkan tepat satu Ketua Tim');
    }

    public function test_draf_save_through_component_and_caption(): void
    {
        $page = $this->form()->set('diinput_oleh', $this->budi->id)->set('klasifikasi_id', $this->klas('KHUSUS'))
            ->set('periode_diperiksa', 'Tahun Anggaran 2025')
            ->call('toggleTim', $this->tim1->id)->call('simpan')
            ->assertHasNoErrors()->assertDispatched('toast', type: 'success', message: 'Draf tersimpan')
            ->assertDispatched('tersimpan')->assertSee('Draf tersimpan 10.42');
        $spt = Spt::sole();
        $this->assertSame(StatusPengajuan::Draf, $spt->status_pengajuan);
        $this->assertSame([$this->ppj->id, $this->budi->id], $spt->personel->pluck('pegawai_id')->all());
        $page->assertSet('sptId', $spt->id);

        // Second save updates the same SPT.
        $page->set('catatan_bagian_umum', 'Mohon diproses.')->call('simpan');
        $this->assertSame(1, Spt::count());
        $this->assertSame('Mohon diproses.', $spt->fresh()->catatan_bagian_umum);
    }

    public function test_unchecked_include_is_excluded_on_submit_and_ajukan_redirects(): void
    {
        $page = $this->lengkap()->call('toggleTim', $this->tim2->id)->set('personel.2.ikut', false)
            ->call('ajukan')->assertHasNoErrors()->assertRedirect(route('irban.spt'));
        $spt = Spt::sole();
        $this->assertSame(StatusPengajuan::MenungguNomor, $spt->status_pengajuan);
        $this->assertSame([$this->ppj->id, $this->budi->id], $spt->personel->pluck('pegawai_id')->all());
        $this->assertSame([PeranTim::SupervisorDalnis, PeranTim::Ketua], $spt->personel->pluck('peran')->all());
        $this->assertSame([$this->objek->id], $spt->objek->pluck('id')->all());
        $this->assertSame('Pengajuan terkirim ke Bagian Umum', session('toast')['message']);
    }

    public function test_lampiran_upload_and_remove(): void
    {
        $page = $this->lengkap()->set('lampiran', PdfUji::buat('draf.pdf', 100))->call('simpan')->assertHasNoErrors();
        $spt = Spt::sole();
        $dok = $spt->dokumen()->sole();
        $page->assertSee('draf.pdf')->assertSee(route('spt.dokumen', [$spt, $dok]));

        $page->call('hapusLampiran')->call('simpan');
        $this->assertSame(0, $spt->dokumen()->count());

        $this->lengkap()->set('lampiran', UploadedFile::fake()->create('x.docx', 10, 'application/msword'))->call('simpan')
            ->assertHasErrors('lampiran')->assertSee('Lampiran harus PDF dan maksimal 5 MB.');
    }

    public function test_edit_loads_spt_and_shows_bu_catatan_for_perlu_revisi(): void
    {
        $spt = Spt::factory()->perluRevisi()->create(['irban_id' => $this->irban->id, 'dibuat_oleh' => $this->user->id, 'pkpt_item_id' => null]);
        $spt->personel()->create(['pegawai_id' => $this->budi->id, 'peran' => 'ketua', 'urutan' => 1, 'sumber' => 'tambahan']);
        $spt->riwayat()->create(['kejadian' => KejadianSpt::PERLU_REVISI, 'alasan' => 'Periode pemeriksaan belum tepat.', 'oleh' => $this->user->id]);

        $this->actingAs($this->user)->get(route('irban.spt.ubah', $spt))->assertOk()->assertSee('Periode pemeriksaan belum tepat.');
        $this->form($spt)->assertSet('sptId', $spt->id)->assertSet('periode_diperiksa', 'Tahun Anggaran 2025')
            ->assertSet('kode_arsip_id', $spt->kode_arsip_id)->assertSet('personel.0.pegawai_id', $this->budi->id)->assertSet('personel.0.peran', 'ketua');
    }

    public function test_draf_saved_as_non_pkpt_reloads_checked(): void
    {
        $this->form()->set('diinput_oleh', $this->budi->id)->set('klasifikasi_id', $this->klas('KHUSUS'))
            ->set('non_pkpt', true)->call('simpan')->assertHasNoErrors();
        $this->form(Spt::sole())->assertSet('non_pkpt', true);

        $reguler = Spt::factory()->create(['irban_id' => $this->irban->id, 'klasifikasi_id' => $this->klas('REGULER'), 'pkpt_item_id' => null]);
        $this->form($reguler)->assertSet('non_pkpt', false);
    }

    public function test_edit_only_own_irban_and_editable_statuses(): void
    {
        $lain = Spt::factory()->create();
        $this->actingAs($this->user)->get(route('irban.spt.ubah', $lain))->assertForbidden();

        $terkirim = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]);
        $this->actingAs($this->user)->get(route('irban.spt.ubah', $terkirim))->assertRedirect(route('irban.spt'));
        $this->assertSame('SPT ini sudah dikirim dan tidak dapat diubah.', session('toast')['message']);

        // Tampered locked id cannot be changed from the client.
        $own = Spt::factory()->create(['irban_id' => $this->irban->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->form($own)->set('sptId', $lain->id);
    }

    public function test_spt_saya_lists_only_own_irban_with_status_filters(): void
    {
        Spt::factory()->create(['irban_id' => $this->irban->id, 'uraian_untuk' => 'Draf milik saya']);
        Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'uraian_untuk' => 'Bernomor milik saya', 'nomor_lengkap' => '700/1/IRBAN-I/2026']);
        Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id, 'uraian_untuk' => 'Menunggu milik saya']);
        Spt::factory()->create(['uraian_untuk' => 'Milik irban lain']);

        $page = Livewire::actingAs($this->user)->test(SptSaya::class)
            ->assertSee('Draf milik saya')->assertSee('Bernomor milik saya')->assertSee('700/1/IRBAN-I/2026')
            ->assertSee('Nomor belum terbit')->assertDontSee('Milik irban lain')
            ->assertSee('Ubah')->assertSee('Penyusunan Laporan');
        $page->set('status', 'menunggu_nomor')->assertSee('Menunggu milik saya')->assertDontSee('Draf milik saya');
        $page->set('status', 'penyusunan_laporan')->assertSee('Bernomor milik saya')->assertDontSee('Menunggu milik saya');
        $page->set('status', '')->set('tahun', '2025')->assertDontSee('Draf milik saya')->assertSee('Belum ada SPT');
    }

    public function test_spt_saya_filters_by_selesai(): void
    {
        Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'uraian_untuk' => 'Masih jalan', 'nomor_lengkap' => '700/2/IRBAN-I/2026']);
        Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'uraian_untuk' => 'Sudah tuntas', 'nomor_lengkap' => '700/3/IRBAN-I/2026', 'ditandai_selesai_pada' => now()]);

        Livewire::actingAs($this->user)->test(SptSaya::class)->assertSeeHtml('value="selesai"')
            ->set('status', 'selesai')->assertSee('Sudah tuntas')->assertDontSee('Masih jalan');
    }

    public function test_spt_saya_per_page_is_clamped(): void
    {
        Livewire::actingAs($this->user)->test(SptSaya::class)->set('perPage', 100000)->assertSet('perPage', 25)
            ->set('perPage', 50)->assertSet('perPage', 50);
    }

    public function test_spt_saya_empty_state_cta(): void
    {
        Livewire::actingAs($this->user)->test(SptSaya::class)->assertSee('Belum ada SPT')->assertSee(route('irban.spt.ajukan'));
    }
}
