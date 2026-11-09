<?php

namespace Tests\Feature\BagianUmum;

use App\Actions\Spt\BatalkanNomor;
use App\Actions\Spt\CatatNomorManual;
use App\Actions\Spt\TerimaPengajuan;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Enums\SumberSpt;
use App\Exports\RegisterSheet;
use App\Livewire\BagianUmum\Register;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\NomorCounter;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\RegisterNomor;
use App\Services\Settings;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Register Nomor SPT, catat nomor manual, batalkan nomor, nomor awal, ekspor (design/dashboard/bagian-umum.md "Register"). */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private Irban $irban3;

    private User $bu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create(['nama' => 'Irban II', 'kode_nomor' => 'INSPEKTORAT-II']);
        $this->irban3 = Irban::factory()->create(['nama' => 'Irban III', 'kode_nomor' => 'INSPEKTORAT-III']);
        $this->bu = User::factory()->create(['role' => Role::BagianUmum]);
    }

    private function terbit(string $uraian, ?Irban $irban = null): Spt
    {
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => ($irban ?? $this->irban)->id, 'uraian_untuk' => $uraian]);

        return app(TerimaPengajuan::class)->handle($this->bu, $spt);
    }

    private function manual(array $o = []): array
    {
        return $o + [
            'irban_id' => $this->irban->id, 'klasifikasi_id' => Klasifikasi::where('kode', 'KHUSUS')->value('id'),
            'kode_arsip_id' => KodeArsip::where('kode', '700')->value('id'), 'uraian_untuk' => 'Pemeriksaan mendesak',
            'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-25', 'alasan' => 'Perintah mendadak dari Bupati',
        ];
    }

    private function register()
    {
        return Livewire::actingAs($this->bu)->test(Register::class);
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

    public function test_route_nav_and_other_roles_forbidden(): void
    {
        $this->actingAs($this->bu)->get('/bagian-umum/register')->assertOk()->assertSee('Register Nomor SPT')->assertSee('Catat Nomor Manual');
        $this->assertSame(['label' => 'Register Nomor SPT', 'route' => 'bagian-umum.register', 'icon' => 'book-open', 'aktif' => ['spt.show']],
            collect(Navigation::links(Role::BagianUmum))->firstWhere('route', 'bagian-umum.register'));

        foreach ([Role::Admin, Role::Pimpinan, Role::Irban] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get('/bagian-umum/register')->assertForbidden();
            Livewire::actingAs($u)->test(Register::class)->assertForbidden();
        }
    }

    public function test_manual_number_takes_next_number_of_same_sequence(): void
    {
        $a = $this->terbit('Satu');
        $m = app(CatatNomorManual::class)->handle($this->bu, $this->manual());
        $b = $this->terbit('Tiga');

        $this->assertSame([1, 2, 3], [$a->nomor_urut, $m->nomor_urut, $b->nomor_urut]);
        $this->assertSame('700/2/INSPEKTORAT-II/SPT/2026', $m->nomor_lengkap);
        $this->assertSame(SumberSpt::Manual, $m->sumber);
        $this->assertSame(StatusPengajuan::Diterima, $m->status_pengajuan);
        $this->assertSame('2026-10-19', $m->tanggal_spt->toDateString());
        $this->assertSame(2026, $m->tahun);
        $this->assertSame(3, NomorCounter::first()->nomor_terakhir);
        $this->assertSame('Perintah mendadak dari Bupati', $m->riwayat()->where('kejadian', 'dicatat_manual')->first()->alasan);
    }

    public function test_manual_number_validation_and_authorization(): void
    {
        $aksi = app(CatatNomorManual::class);
        $this->gagal(fn () => $aksi->handle($this->bu, $this->manual(['alasan' => 'pendek'])), 'alasan', 'Alasan minimal 10 karakter.');
        $this->gagal(fn () => $aksi->handle($this->bu, $this->manual(['uraian_untuk' => ''])), 'uraian_untuk', 'Perihal wajib diisi.');
        $this->gagal(fn () => $aksi->handle($this->bu, $this->manual(['tanggal_selesai' => '2026-10-01'])), 'tanggal_selesai', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        $this->gagal(fn () => $aksi->handle($this->bu, $this->manual(['kode_arsip_id' => ''])), 'kode_arsip_id', 'Pilih kode arsip.');
        $this->gagal(fn () => $aksi->handle($this->bu, $this->manual(['kode_arsip_id' => 999999])), 'kode_arsip_id', 'Pilih kode arsip.');
        $this->assertSame(0, Spt::count());
        $this->assertSame(0, NomorCounter::count());

        $this->expectException(AuthorizationException::class);
        $aksi->handle(User::factory()->admin()->create(), $this->manual());
    }

    public function test_cancel_rules_and_messages(): void
    {
        $spt = $this->terbit('Akan dibatalkan');
        $aksi = app(BatalkanNomor::class);

        $this->gagal(fn () => $aksi->handle($this->bu, $spt, 'pendek'), 'alasan', 'Alasan pembatalan minimal 10 karakter.');
        $this->assertSame(StatusPengajuan::Diterima, $spt->fresh()->status_pengajuan);

        $hasil = $aksi->handle($this->bu, $spt, 'Salah ketik perihal SPT');
        $this->assertSame(StatusPengajuan::Dibatalkan, $hasil->status_pengajuan);
        $this->assertSame(['nomor_urut' => 1, 'nomor_lengkap' => '700/1/INSPEKTORAT-II/SPT/2026'], $hasil->only(['nomor_urut', 'nomor_lengkap']));
        $this->assertSame('Salah ketik perihal SPT', $hasil->riwayat()->where('kejadian', 'dibatalkan')->first()->alasan);

        // Already cancelled / not yet numbered.
        $this->gagal(fn () => $aksi->handle($this->bu, $spt, 'Alasan yang cukup panjang'), 'spt', 'Hanya nomor yang sudah terbit yang dapat dibatalkan.');
        $menunggu = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]);
        $this->gagal(fn () => $aksi->handle($this->bu, $menunggu, 'Alasan yang cukup panjang'), 'spt', 'Hanya nomor yang sudah terbit yang dapat dibatalkan.');
    }

    public function test_cancelled_number_is_never_reused(): void
    {
        $satu = $this->terbit('Satu');
        app(BatalkanNomor::class)->handle($this->bu, $satu, 'Dibatalkan oleh pimpinan');
        $dua = $this->terbit('Dua');

        $this->assertSame(2, $dua->nomor_urut);
        $this->assertSame(2, NomorCounter::first()->nomor_terakhir);
        $this->register()->assertSeeInOrder(['700/1/INSPEKTORAT-II/SPT/2026', 'Dibatalkan', '700/2/INSPEKTORAT-II/SPT/2026'])
            ->assertSee('Dibatalkan: Dibatalkan oleh pimpinan');
    }

    public function test_component_cancel_and_manual_flow(): void
    {
        $spt = $this->terbit('Contoh');
        $this->register()
            ->call('bukaBatal', $spt->id)->assertSet('showBatal', true)
            ->set('alasan', 'x')->call('batalkan')->assertHasErrors(['alasan'])
            ->set('alasan', 'Salah ketik perihal SPT')->call('batalkan')->assertHasNoErrors()->assertSet('showBatal', false)->assertDispatched('toast');
        $this->assertSame(StatusPengajuan::Dibatalkan, $spt->fresh()->status_pengajuan);

        $c = $this->register()->call('bukaManual')->assertSet('showManual', true)->assertSee('Gunakan hanya untuk SPT mendesak.')
            ->set('manual.irban_id', (string) $this->irban->id)->assertSet('pratinjau', '')
            ->set('manual.klasifikasi_id', (string) Klasifikasi::where('kode', 'KHUSUS')->value('id'))
            ->set('manual.kode_arsip_id', (string) KodeArsip::where('kode', '800')->value('id'))->assertSet('pratinjau', '800/2/INSPEKTORAT-II/SPT/2026');
        $c->set('manual', $this->manual(['irban_id' => (string) $this->irban->id]))->call('catatManual')->assertHasNoErrors()->assertSet('showManual', false)
            ->assertDispatched('toast', type: 'success', message: 'Nomor 700/2/INSPEKTORAT-II/SPT/2026 dicatat');
        $this->assertSame(1, Spt::where('sumber', 'manual')->count());
    }

    /** DESIGN 10: the number link is a 24px target on desktop, 44px below lg. */
    public function test_number_link_has_a_touch_target(): void
    {
        $spt = $this->terbit('Contoh');
        $this->register()->assertSeeHtml('<a href="'.route('spt.show', $spt).'" wire:navigate class="inline-flex min-h-11 items-center font-medium text-mint-700 underline-offset-2 hover:underline max-sm:max-w-32 lg:min-h-6">');
    }

    public function test_cancel_only_offered_for_diterima_and_tampered_ids_fail(): void
    {
        $spt = $this->terbit('Contoh');
        $batal = Spt::factory()->dibatalkan()->create(['irban_id' => $this->irban->id]);
        $menunggu = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]);
        $c = $this->register();
        $c->assertSeeHtml('aria-label="Batalkan Nomor '.$spt->nomor_lengkap.'"')->assertDontSeeHtml('aria-label="Batalkan Nomor '.$batal->nomor_lengkap.'"');

        $c->call('bukaBatal', $batal->id)->assertSet('showBatal', false);
        $c->call('bukaBatal', $menunggu->id)->assertSet('showBatal', false);
        $c->call('bukaBatal', 99999)->assertSet('showBatal', false);
    }

    public function test_nomor_awal(): void
    {
        $this->register()->call('bukaAwal')->assertSet('showAwal', true)->assertSee('Nomor terakhir dari stambook fisik')
            ->set('awalNomor', '751')->call('aturAwal')->assertHasNoErrors()->assertSet('showAwal', false);
        $this->assertSame(751, NomorCounter::where('tahun', 2026)->first()->nomor_terakhir);

        $this->assertSame(752, $this->terbit('Pertama')->nomor_urut);

        $this->register()->assertSee('Nomor awal tidak dapat diubah')->call('bukaAwal')
            ->set('awalNomor', '10')->call('aturAwal')->assertHasErrors(['awalNomor']);
        $this->assertSame(752, NomorCounter::first()->nomor_terakhir);
    }

    public function test_nomor_awal_needs_irban_when_per_irban(): void
    {
        app(Settings::class)->set('penomoran.scope', 'per_irban');
        $c = $this->register()->call('bukaAwal')->assertSee('Irban')->set('awalNomor', '5')->call('aturAwal')->assertHasErrors(['awalIrban']);
        $c->set('awalIrban', (string) $this->irban->id)->call('aturAwal')->assertHasNoErrors();
        $this->assertSame("irban:{$this->irban->id}", NomorCounter::first()->scope);
    }

    public function test_filters_search_order_and_pagination(): void
    {
        $a = $this->terbit('Satu', $this->irban);
        $b = $this->terbit('Dua', $this->irban3);
        $c = $this->terbit('Tiga', $this->irban);
        $b->forceFill(['tanggal_spt' => '2026-03-05'])->save();
        app(BatalkanNomor::class)->handle($this->bu, $c, 'Salah ketik perihal SPT');
        Spt::factory()->menungguNomor()->create(['uraian_untuk' => 'Belum bernomor']);
        Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'tahun' => 2025, 'nomor_urut' => 9, 'nomor_lengkap' => '700/9/X/SPT/2025', 'uraian_untuk' => 'Tahun lalu']);

        $this->register()->assertSet('tahun', 2026)->assertSeeInOrder([$a->nomor_lengkap, $b->nomor_lengkap, $c->nomor_lengkap])
            ->assertDontSee('Belum bernomor')->assertDontSee('700/9/X/SPT/2025')
            ->set('tahun', 2025)->assertSee('700/9/X/SPT/2025')->assertDontSee($a->nomor_lengkap)
            ->set('tahun', 2026)->set('cari', '/2/')->assertSee($b->nomor_lengkap)->assertDontSee($a->nomor_lengkap)
            ->set('cari', '')->set('irban', (string) $this->irban3->id)->assertSee($b->nomor_lengkap)->assertDontSee($a->nomor_lengkap)
            ->set('irban', '')->set('bulan', '3')->assertSee($b->nomor_lengkap)->assertDontSee($a->nomor_lengkap)
            ->set('bulan', '')->set('status', 'dibatalkan')->assertSee($c->nomor_lengkap)->assertDontSee($a->nomor_lengkap)
            ->set('status', 'diterima')->assertSee($a->nomor_lengkap)->assertSee($b->nomor_lengkap)->assertDontSee($c->nomor_lengkap)
            // Old progress values from bookmarked URLs mean "Semua".
            ->set('status', 'pelaksanaan')->assertSee($a->nomor_lengkap)->assertSee($c->nomor_lengkap);
    }

    /** Spec 1.1.4 Task 1: the 16 columns of a system SPT, in order. */
    public function test_row_of_a_system_spt_has_the_16_columns(): void
    {
        $jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu LKPD']);
        $item = PkptItem::factory()->create(['irban_id' => $this->irban->id, 'jenis_penugasan_id' => $jenis->id]);
        $desa = Objek::factory()->create(['nama' => 'Desa Muara', 'jenis' => 'desa', 'parent_id' => Objek::factory()->create(['nama' => 'Kec Lawang'])->id]);
        $spt = Spt::factory()->diterima()->create([
            'irban_id' => $this->irban->id, 'pkpt_item_id' => $item->id, 'jenis_penugasan_id' => $jenis->id, 'nomor_lengkap' => '700/5/INSPEKTORAT-II/SPT/2026',
            'ppj_pegawai_id' => Pegawai::factory()->create(['nama_bergelar' => 'Drs. Penanggung, M.Si'])->id, 'catatan_bagian_umum' => 'Mohon segera',
        ]);
        $spt->forceFill(['nomor_sppd' => '090/12/SPPD/2026'])->save();
        $spt->objek()->attach($desa->id);
        foreach ([['Sup', 'supervisor_dalnis'], ['Ketua', 'ketua'], ['Anggota Satu', 'anggota']] as $u => [$nama, $peran]) {
            $spt->personel()->create(['pegawai_id' => Pegawai::factory()->create(['nama_bergelar' => $nama])->id, 'peran' => $peran, 'urutan' => $u + 1, 'sumber' => 'tambahan']);
        }

        $this->assertSame([
            '700/5/INSPEKTORAT-II/SPT/2026', '19 Oktober 2026', '090/12/SPPD/2026', Klasifikasi::where('kode', 'KHUSUS')->value('nama'), 'Reviu LKPD · 2–20 Maret 2026',
            'Reviu LKPD', 'Kec Lawang › Desa Muara', '02 Maret 2026', '20 Maret 2026', 'Tahun Anggaran 2025', 'Melaksanakan pemeriksaan khusus.', 'Drs. Penanggung, M.Si',
            'Sup (Supervisor/Dalnis); Ketua (Ketua Tim); Anggota Satu (Anggota)', 'Diterima', 'Mohon segera', '–',
        ], app(RegisterNomor::class)->baris($spt->fresh()));

        $this->register()->assertSeeInOrder(['Sup (Supervisor/Dalnis)', '+ 2 lainnya', 'Diterima'])->assertSee('Ketua (Ketua Tim)')
            ->assertSee('090/12/SPPD/2026')->assertSeeHtml('aria-controls="anggota-'.$spt->id.'"');
    }

    public function test_manual_and_cancelled_rows_fill_empty_cells_with_a_dash(): void
    {
        $m = app(CatatNomorManual::class)->handle($this->bu, $this->manual());
        $b = $this->terbit('Akan dibatalkan');
        app(BatalkanNomor::class)->handle($this->bu, $b, 'Salah ketik perihal SPT');
        $svc = app(RegisterNomor::class);

        $this->assertSame([
            $m->nomor_lengkap, '19 Oktober 2026', '–', Klasifikasi::where('kode', 'KHUSUS')->value('nama'), 'Non-PKPT', '–', '–', '20 Oktober 2026',
            '25 Oktober 2026', '–', 'Pemeriksaan mendesak', '–', '–', 'Diterima', '–', 'Manual',
        ], $svc->baris($m->fresh()));
        $baris = $svc->baris($b->fresh());
        $this->assertCount(16, $baris);
        $this->assertSame(['Dibatalkan', 'Dibatalkan: Salah ketik perihal SPT'], [$baris[13], $baris[15]]);
        $this->register()->assertSee('Dibatalkan: Salah ketik perihal SPT')->assertSee('Manual');
    }

    public function test_invalid_status_from_an_old_bookmark_is_reset(): void
    {
        Livewire::withQueryParams(['status' => 'selesai'])->actingAs($this->bu)->test(Register::class)->assertSet('status', '');
        Livewire::withQueryParams(['status' => 'diterima'])->actingAs($this->bu)->test(Register::class)->assertSet('status', 'diterima');
    }

    public function test_status_filter_is_status_pengajuan_and_named_in_the_pdf_header(): void
    {
        $a = $this->terbit('Satu');
        $b = $this->terbit('Dua');
        app(BatalkanNomor::class)->handle($this->bu, $b, 'Salah ketik perihal SPT');
        $svc = app(RegisterNomor::class);

        $this->assertSame([$a->id], $svc->query(['tahun' => 2026, 'status' => 'diterima'])->pluck('id')->all());
        $this->assertSame([$b->id], $svc->query(['tahun' => 2026, 'status' => 'dibatalkan'])->pluck('id')->all());
        $this->assertCount(2, $svc->query(['tahun' => 2026, 'status' => 'menunggu_nomor'])->get());
        $this->assertSame(['Status: Diterima'], $svc->ringkasanFilter(['tahun' => 2026, 'status' => 'diterima']));
        $this->assertSame(['Status: Dibatalkan'], $svc->ringkasanFilter(['tahun' => 2026, 'status' => 'dibatalkan']));
        $this->assertSame([], $svc->ringkasanFilter(['tahun' => 2026, 'status' => 'selesai']));
        $this->register()->assertSeeHtml('<option value="diterima"')->assertSeeHtml('<option value="dibatalkan"')->assertDontSeeHtml('<option value="pelaksanaan"');
    }

    /** No N+1: the page and the export run the same number of queries for 10 and 100 fully filled rows. */
    public function test_query_count_is_constant(): void
    {
        $jenis = JenisPenugasan::factory()->create();
        $item = PkptItem::factory()->create(['irban_id' => $this->irban->id, 'jenis_penugasan_id' => $jenis->id]);
        $buat = function (int $n) use ($jenis, $item) {
            for ($i = 0; $i < $n; $i++) {
                $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'pkpt_item_id' => $item->id, 'jenis_penugasan_id' => $jenis->id,
                    'ppj_pegawai_id' => Pegawai::factory()->create()->id, 'catatan_bagian_umum' => 'Catatan']);
                $spt->objek()->attach(Objek::factory()->create(['jenis' => 'desa', 'parent_id' => Objek::factory()->create()->id])->id);
                $spt->personel()->create(['pegawai_id' => Pegawai::factory()->create()->id, 'peran' => 'ketua', 'urutan' => 1, 'sumber' => 'tambahan']);
                $spt->personel()->create(['pegawai_id' => Pegawai::factory()->create()->id, 'peran' => 'anggota', 'urutan' => 2, 'sumber' => 'tambahan']);
            }
        };
        $hitung = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->bu)->get('/bagian-umum/register')->assertOk()->assertSee('lainnya');
            app(RegisterNomor::class)->semua(['tahun' => 2026]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $buat(10);
        $hitung(); // warm-up: the settings cache is read once per process
        $sepuluh = $hitung();
        $buat(90);
        $this->assertSame($sepuluh, $hitung());
    }

    public function test_pagination_is_100_per_page(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'nomor_urut' => $i, 'nomor_lengkap' => "N/{$i}/X"]);
        }
        $this->register()->assertSee('N/100/X')->assertDontSee('N/101/X')->assertSee('Menampilkan 1–100 dari 101')
            ->call('gotoPage', 2)->assertSee('N/101/X')->assertDontSee('N/100/X');
    }

    public function test_excel_export_matches_table_rows_and_follows_filters(): void
    {
        $this->terbit('Satu', $this->irban);
        $b = $this->terbit('Dua', $this->irban3);
        app(BatalkanNomor::class)->handle($this->bu, $b, 'Salah ketik perihal SPT');
        Excel::fake();

        $this->register()->set('status', 'dibatalkan')->call('ekspor', 'xlsx');
        Excel::assertDownloaded('register-nomor-spt-2026.xlsx', function (RegisterSheet $e) use ($b) {
            $this->assertSame(RegisterNomor::KOLOM, $e->array()[3]);
            $this->assertCount(5, $e->array());
            $this->assertSame([$b->nomor_lengkap, 'Dua', 'Dibatalkan', 'Dibatalkan: Salah ketik perihal SPT'], array_values(array_intersect_key($e->array()[4], array_flip([0, 10, 13, 15]))));

            return true;
        });
    }

    public function test_real_xlsx_and_pdf_downloads(): void
    {
        $this->terbit('Satu', $this->irban);
        $this->terbit('Dua', $this->irban3);

        $this->register()->set('irban', (string) $this->irban->id)->call('ekspor', 'xlsx')->assertFileDownloaded('register-nomor-spt-2026.xlsx');
        $this->register()->set('irban', (string) $this->irban->id)->call('ekspor', 'pdf')->assertFileDownloaded('register-nomor-spt-2026.pdf');
    }

    public function test_pdf_is_a_pdf_with_filtered_content(): void
    {
        $this->terbit('Satu', $this->irban);
        $this->terbit('Dua', $this->irban3);
        $f = ['tahun' => 2026, 'irban' => $this->irban3->id];
        $svc = app(RegisterNomor::class);

        $html = $svc->html($f);
        $this->assertStringContainsString('Register Nomor SPT 2026', $html);
        $this->assertStringContainsString('Irban: Irban III', $html);
        $this->assertStringContainsString('700/2/INSPEKTORAT-III/SPT/2026', $html);
        $this->assertStringNotContainsString('700/1/INSPEKTORAT-II/SPT/2026', $html);
        $this->assertStringStartsWith('%PDF', $svc->pdf($f));
    }
}
