<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\CatatNomorManual;
use App\Actions\Spt\TerimaPengajuan;
use App\Actions\Spt\UbahNomorSppd;
use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Livewire\BagianUmum\Pengajuan;
use App\Livewire\BagianUmum\Register;
use App\Livewire\Spt\Detail;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Nomor SPPD (versi 1.1.4 Task 2): ability ubahSppd, riwayat lama/baru, duplicate warning, the three inputs. */
class NomorSppdTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $bu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create(['nama' => 'Irban II', 'kode_nomor' => 'INSPEKTORAT-II']);
        $this->bu = User::factory()->create(['role' => Role::BagianUmum]);
    }

    private function spt(string $state = 'diterima', array $attrs = []): Spt
    {
        return Spt::factory()->{$state}()->create(['irban_id' => $this->irban->id, ...$attrs]);
    }

    private function detail(Spt $spt, ?User $u = null)
    {
        return Livewire::actingAs($u ?? $this->bu)->test(Detail::class, ['spt' => $spt]);
    }

    private function sppdRiwayat(Spt $spt)
    {
        return $spt->riwayat()->where('kejadian', KejadianSpt::SPPD_DIUBAH)->orderBy('id')->get();
    }

    public function test_ability_is_bagian_umum_only_and_never_on_dibatalkan(): void
    {
        $spt = $this->spt();
        $this->assertTrue($this->bu->can('ubahSppd', $spt));
        $this->assertTrue($this->bu->can('ubahSppd', $this->spt('menungguNomor')));
        $this->assertFalse($this->bu->can('ubahSppd', $this->spt('dibatalkan')));
        $this->assertFalse($this->bu->can('ubahSppd', $this->spt('ditolak')));
        $this->assertFalse($this->bu->can('ubahSppd', $this->spt('perluRevisi')));
        $this->assertFalse($this->bu->can('ubahSppd', Spt::factory()->create(['irban_id' => $this->irban->id])));
        foreach ([Role::Pimpinan, Role::Admin] as $role) {
            $this->assertFalse(User::factory()->create(['role' => $role])->can('ubahSppd', $spt));
        }
        $irban = User::factory()->irban()->create();
        $irban->forceFill(['irban_id' => $this->irban->id])->save();
        $this->assertFalse($irban->can('ubahSppd', $spt));
    }

    public function test_detail_panel_saves_trimmed_value_and_writes_riwayat_with_old_and_new(): void
    {
        $spt = $this->spt();
        $this->detail($spt)->assertSee('Simpan Nomor SPPD')
            ->set('nomor_sppd', '  090/12/SPPD/2026  ')->call('simpanSppd')
            ->assertHasNoErrors()->assertDispatched('toast', type: 'success', message: 'Nomor SPPD disimpan')->assertSet('nomor_sppd', '090/12/SPPD/2026')
            ->set('nomor_sppd', '090/13/SPPD/2026')->call('simpanSppd')
            ->call('simpanSppd') // unchanged: no riwayat
            ->set('nomor_sppd', '   ')->call('simpanSppd')->assertDispatched('toast', type: 'success', message: 'Nomor SPPD dihapus');

        $this->assertNull($spt->fresh()->nomor_sppd);
        $r = $this->sppdRiwayat($spt);
        $this->assertSame([
            [null, '090/12/SPPD/2026'], ['090/12/SPPD/2026', '090/13/SPPD/2026'], ['090/13/SPPD/2026', null],
        ], $r->map(fn ($x) => [$x->nilai_lama['nomor_sppd'], $x->nilai_baru['nomor_sppd']])->all());
        $this->assertSame($this->bu->id, $r[0]->oleh);
        $this->assertSame('Nomor SPPD diubah', KejadianSpt::label(KejadianSpt::SPPD_DIUBAH));

        $this->detail($spt->fresh())->assertSee('Nomor SPPD diubah')->assertSee('090/12/SPPD/2026 → 090/13/SPPD/2026');
    }

    public function test_more_than_100_characters_is_a_field_error(): void
    {
        $spt = $this->spt();
        $this->detail($spt)->set('nomor_sppd', str_repeat('A', 101))->call('simpanSppd')
            ->assertHasErrors(['nomor_sppd'])->assertSee('Nomor SPPD maksimal 100 karakter.');
        $this->assertNull($spt->fresh()->nomor_sppd);
        $this->detail($spt)->set('nomor_sppd', str_repeat('A', 100))->call('simpanSppd')->assertHasNoErrors();
        $this->assertSame(100, strlen($spt->fresh()->nomor_sppd));
    }

    public function test_other_roles_see_it_read_only_and_get_403_on_the_livewire_endpoint(): void
    {
        $spt = $this->spt('diterima', ['nomor_sppd' => 'SPPD-RO-77']);
        $irban = User::factory()->irban()->create();
        $irban->forceFill(['irban_id' => $this->irban->id])->save();
        foreach ([User::factory()->create(['role' => Role::Pimpinan]), User::factory()->create(['role' => Role::Admin]), $irban] as $u) {
            $this->detail($spt, $u)->assertSee('SPPD-RO-77')->assertDontSee('Simpan Nomor SPPD')
                ->set('nomor_sppd', 'DIRETAS')->call('simpanSppd')->assertForbidden();
        }
        $this->assertSame('SPPD-RO-77', $spt->fresh()->nomor_sppd);
        $this->assertCount(0, $this->sppdRiwayat($spt));

        $this->detail($this->spt(), User::factory()->create(['role' => Role::Pimpinan]))->assertSee('Belum dicatat.');
    }

    public function test_dibatalkan_spt_is_read_only_and_a_stale_tab_save_is_a_toast(): void
    {
        $batal = $this->spt('dibatalkan', ['nomor_sppd' => 'SPPD-LAMA']);
        $this->detail($batal)->assertSee('SPPD-LAMA')->assertDontSee('Simpan Nomor SPPD');

        // Opened while Diterima, cancelled in another tab, then saved.
        $spt = $this->spt();
        $c = $this->detail($spt)->set('nomor_sppd', 'SPPD-BARU');
        $spt->forceFill(['status_pengajuan' => StatusPengajuan::Dibatalkan])->save();
        $c->call('simpanSppd')->assertDispatched('toast', type: 'error', message: UbahNomorSppd::DIBATALKAN)->assertDispatched('fokus-ke', id: 'judul-spt');
        $this->assertNull($spt->fresh()->nomor_sppd);

        $this->expectException(AuthorizationException::class);
        app(UbahNomorSppd::class)->terapkan($this->bu, $batal, 'X');
    }

    public function test_actions_reject_non_bagian_umum(): void
    {
        $this->expectException(AuthorizationException::class);
        app(UbahNomorSppd::class)->handle(User::factory()->create(['role' => Role::Pimpinan]), $this->spt(), 'X');
    }

    public function test_duplicate_in_same_year_warns_case_insensitively_without_blocking(): void
    {
        $lain = $this->spt('diterima', ['nomor_sppd' => 'Abc/7/SPPD']);
        $this->spt('diterima', ['nomor_sppd' => 'ZZZ/1', 'tahun' => 2025]);
        $aksi = app(UbahNomorSppd::class);
        $this->assertTrue($aksi->duplikat(2026, '  abc/7/sppd ')?->is($lain));
        $this->assertNull($aksi->duplikat(2026, 'abc/7/sppd', $lain->id));
        $this->assertNull($aksi->duplikat(2026, 'ZZZ/1'));
        $this->assertNull($aksi->duplikat(2026, ''));
        $this->assertNull($aksi->duplikat(2026, null));

        // Its own stored value is not a duplicate of itself.
        $this->detail($lain)->assertDontSee('juga tercatat pada');
        $spt = $this->spt();
        $this->detail($spt)->assertDontSee('juga tercatat pada')
            ->set('nomor_sppd', 'ABC/7/SPPD')->assertSee('Nomor SPPD ini juga tercatat pada')->assertSee($lain->nomor_lengkap)
            ->call('simpanSppd')->assertHasNoErrors();
        $this->assertSame('ABC/7/SPPD', $spt->fresh()->nomor_sppd);
    }

    public function test_terima_modal_sets_sppd_in_the_same_flow(): void
    {
        $this->spt('diterima', ['nomor_sppd' => 'DUP-1']);
        $spt = $this->spt('menungguNomor');
        $c = Livewire::actingAs($this->bu)->test(Pengajuan::class)->set('pilih', $spt->id)->call('bukaTerima')
            ->assertSee('Nomor SPPD')->set('nomor_sppd', 'dup-1')->assertSee('Nomor SPPD ini juga tercatat pada');

        $c->set('nomor_sppd', str_repeat('B', 101))->call('terima')->assertHasErrors(['nomor_sppd'])->assertSet('showTerima', true);
        $this->assertSame(StatusPengajuan::MenungguNomor, $spt->fresh()->status_pengajuan);

        $c->set('nomor_sppd', ' SPPD-T-1 ')->call('terima')->assertHasNoErrors()->assertSet('showTerima', false);
        $spt->refresh();
        $this->assertSame(StatusPengajuan::Diterima, $spt->status_pengajuan);
        $this->assertSame('SPPD-T-1', $spt->nomor_sppd);
        $this->assertSame([KejadianSpt::DITERIMA, KejadianSpt::SPPD_DIUBAH], $spt->riwayat()->orderBy('id')->pluck('kejadian')->all());

        // Empty field: no SPPD, no extra riwayat.
        $kosong = app(TerimaPengajuan::class)->handle($this->bu, $this->spt('menungguNomor'), '');
        $this->assertNull($kosong->nomor_sppd);
        $this->assertCount(0, $this->sppdRiwayat($kosong));
    }

    public function test_catat_manual_drawer_sets_sppd(): void
    {
        $this->spt('diterima', ['nomor_sppd' => 'DUP-2']);
        $data = ['irban_id' => (string) $this->irban->id, 'klasifikasi_id' => (string) Klasifikasi::where('kode', 'KHUSUS')->value('id'),
            'kode_arsip_id' => (string) KodeArsip::where('kode', '700')->value('id'), 'jenis_penugasan_id' => '',
            'uraian_untuk' => 'Pemeriksaan mendesak', 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-25', 'alasan' => 'Perintah mendadak dari Bupati'];
        $c = Livewire::actingAs($this->bu)->test(Register::class)->call('bukaManual')->assertSee('Nomor SPPD')
            ->set('manual.nomor_sppd', 'DUP-2')->assertSee('Nomor SPPD ini juga tercatat pada')
            ->set('manual.nomor_sppd', str_repeat('C', 101))->set('manual.alasan', '')->call('catatManual')
            ->assertHasErrors(['manual.nomor_sppd', 'manual.alasan']);
        $this->assertSame(0, Spt::where('sumber', 'manual')->count());

        foreach ($data as $k => $v) {
            $c->set("manual.{$k}", $v);
        }
        $c->set('manual.nomor_sppd', ' SPPD-M-1 ')->call('catatManual')->assertHasNoErrors();
        $spt = Spt::where('sumber', 'manual')->sole();
        $this->assertSame('SPPD-M-1', $spt->nomor_sppd);
        $this->assertSame([KejadianSpt::DICATAT_MANUAL, KejadianSpt::SPPD_DIUBAH], $spt->riwayat()->orderBy('id')->pluck('kejadian')->all());

        $tanpa = app(CatatNomorManual::class)->handle($this->bu, $data);
        $this->assertNull($tanpa->nomor_sppd);
        $this->assertCount(0, $this->sppdRiwayat($tanpa));
    }
}
