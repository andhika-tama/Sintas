<?php

namespace Tests\Feature\MasterData;

use App\Enums\Role;
use App\Livewire\Admin\Pengaturan;
use App\Models\Irban;
use App\Models\NomorCounter;
use App\Models\Spt;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PengaturanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_only_admin_can_open(): void
    {
        $this->get('/admin/pengaturan')->assertRedirect('/masuk');
        foreach (['pimpinan', 'irban', 'bagianUmum'] as $role) {
            $this->actingAs(User::factory()->$role()->create())->get('/admin/pengaturan')->assertForbidden();
        }
        $this->actingAs($this->admin())->get('/admin/pengaturan')->assertOk()
            ->assertSee('Pengaturan Sistem')->assertSee('Simpan perubahan')->assertSee('Kategori kendala');
    }

    public function test_non_admin_cannot_call_save(): void
    {
        Livewire::actingAs(User::factory()->irban()->create())->test(Pengaturan::class)->assertForbidden();
    }

    public function test_each_card_saves_only_its_own_keys(): void
    {
        $s = app(Settings::class);
        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.penomoran.format', '{kode_arsip}/{nomor_urut}/SPT/{tahun}')
            ->set('v.upload.maks_mb', 50)
            ->call('save', 'penomoran')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertSame('{kode_arsip}/{nomor_urut}/SPT/{tahun}', $s->get('penomoran.format'));
        $this->assertSame(10, $s->get('upload.maks_mb'));
        $this->assertDatabaseMissing('settings', ['key' => 'upload.maks_mb']);
    }

    public function test_format_requires_nomor_urut_and_tahun(): void
    {
        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.penomoran.format', '{kode_arsip}/{tahun}')
            ->call('save', 'penomoran')->assertHasErrors('v.penomoran.format');
        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.penomoran.format', '{nomor_urut}/{tahun}')
            ->call('save', 'penomoran')->assertHasNoErrors();
    }

    /** Fase 11.2 Task 6 butir 21: only known placeholders, and a per-irban sequence needs {kode_irban} (else nomor_lengkap repeats). */
    public function test_format_hanya_placeholder_sah_dan_per_irban_wajib_kode_irban(): void
    {
        $h = Livewire::actingAs($this->admin())->test(Pengaturan::class);
        $h->set('v.penomoran.format', '{nomor_urut}/{kode}/{tahun}')->call('save', 'penomoran')->assertHasErrors('v.penomoran.format');
        $this->assertSame('Placeholder tidak dikenal: {kode}.', $h->errors()->first('v.penomoran.format'));
        $h->set('v.penomoran.format', '{nomor_urut}/{tahun}')->set('v.penomoran.scope', 'per_irban')->call('save', 'penomoran')->assertHasErrors('v.penomoran.format');
        $this->assertSame('Cakupan per irban membutuhkan {kode_irban} di format, agar nomor antar-irban tidak sama.', $h->errors()->first('v.penomoran.format'));
        $h->set('v.penomoran.format', '{kode_arsip}/{nomor_urut}/{kode_irban}/{klasifikasi}/SPT/{tahun}')->call('save', 'penomoran')->assertHasNoErrors();
        $this->assertSame('per_irban', app(Settings::class)->get('penomoran.scope'));
    }

    public function test_enum_and_number_validation(): void
    {
        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.penomoran.scope', 'lain')->call('save', 'penomoran')->assertHasErrors('v.penomoran.scope')
            ->set('v.penanda.batas_terlambat_hari', 0)->call('save', 'penugasan')->assertHasErrors('v.penanda.batas_terlambat_hari')
            ->set('v.upload.maks_mb', 'abc')->call('save', 'unggahan')->assertHasErrors('v.upload.maks_mb');
    }

    public function test_kategori_add_remove_min_one_and_lainnya_stays(): void
    {
        $c = Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->call('addKategori')->assertSet('v.kendala.kategori.5', '')
            ->call('save', 'kendala')->assertHasErrors('v.kendala.kategori.5')
            ->set('v.kendala.kategori.5', 'Cuaca buruk')
            ->call('save', 'kendala')->assertHasNoErrors();
        $this->assertContains('Cuaca buruk', app(Settings::class)->get('kendala.kategori'));

        $c->call('removeKategori', 5)->assertCount('v.kendala.kategori', 5);

        // "Lainnya" cannot be removed.
        $c->call('removeKategori', 4)->assertCount('v.kendala.kategori', 5);

        // Minimum one.
        $c->set('v.kendala.kategori', [])->call('save', 'kendala')->assertHasErrors('v.kendala.kategori');
        $c->set('v.kendala.kategori', ['Satu'])->call('save', 'kendala')->assertHasErrors('v.kendala.kategori'); // missing Lainnya
    }

    public function test_saved_values_are_loaded(): void
    {
        app(Settings::class)->set('penomoran.format', '{nomor_urut}/{tahun}');
        Livewire::actingAs($this->admin())->test(Pengaturan::class)->assertSet('v.penomoran.format', '{nomor_urut}/{tahun}')
            ->assertDontSeeHtml('name="penomoran_kode_arsip"');
    }

    public function test_demoted_admin_is_rejected_on_every_action_and_nothing_changes(): void
    {
        $admin = $this->admin();
        // A forbidden call leaves the test component unusable, so mount a fresh one per action, then demote.
        $demoted = function (string $method, ...$args) use ($admin) {
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Pengaturan::class)->set('v.penomoran.format', '{nomor_urut}/X/{tahun}')->set('v.upload.maks_mb', 77);
            $admin->role = Role::Irban;
            $c->call($method, ...$args)->assertForbidden();
        };

        foreach (array_keys(Settings::GROUPS) as $group) {
            $demoted('save', $group);
        }
        $demoted('addKategori');
        $demoted('removeKategori', 0);

        $this->assertDatabaseCount('settings', 0);
        $this->assertCount(5, app(Settings::class)->get('kendala.kategori'));
    }

    public function test_changing_scope_moves_every_new_register_past_the_highest_number_and_logs_it(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 5, 4));
        [$a, $b] = Irban::factory()->count(2)->create();
        // An imported number with no counter still counts: numbers are never reused.
        Spt::factory()->for($a)->diterima()->create(['nomor_urut' => 7, 'nomor_lengkap' => '700/7/X/SPT/2026', 'tahun' => 2026]);

        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.penomoran.scope', 'per_irban')->call('save', 'penomoran')->assertHasNoErrors();

        foreach ([$a, $b] as $irban) {
            $counter = NomorCounter::where(['tahun' => 2026, 'scope' => "irban:{$irban->id}"])->firstOrFail();
            $this->assertSame(7, $counter->nomor_terakhir);
            $log = Activity::where('subject_type', $counter->getMorphClass())->where('subject_id', $counter->id)->where('event', 'updated')->firstOrFail();
            $this->assertSame(7, $log->properties['attributes']['nomor_terakhir']);
        }
    }

    public function test_unknown_group_is_404(): void
    {
        Livewire::actingAs($this->admin())->test(Pengaturan::class)->call('save', 'nope')->assertNotFound();
    }

    public function test_kategori_values_are_trimmed_before_validation_and_saved(): void
    {
        Livewire::actingAs($this->admin())->test(Pengaturan::class)
            ->set('v.kendala.kategori.4', 'Lainnya ')
            ->call('addKategori')->set('v.kendala.kategori.5', '  Cuaca buruk  ')
            ->call('save', 'kendala')->assertHasNoErrors();
        $saved = app(Settings::class)->get('kendala.kategori');
        $this->assertSame('Lainnya', $saved[4]);
        $this->assertSame('Cuaca buruk', $saved[5]);
    }

    public function test_removing_a_middle_kategori_keeps_the_right_rows_and_stable_ids(): void
    {
        $c = Livewire::actingAs($this->admin())->test(Pengaturan::class);
        $ids = $c->get('kategoriIds');
        $c->call('removeKategori', 1);

        $this->assertSame(
            ['Jadwal PKPT belum disahkan', 'Personel tidak tersedia', 'Objek tidak siap', 'Lainnya'],
            $c->get('v.kendala.kategori'),
        );
        $this->assertSame([$ids[0], $ids[2], $ids[3], $ids[4]], $c->get('kategoriIds'));
        $c->assertSeeHtml('wire:key="kategori-'.$ids[2].'"')->assertDontSeeHtml('wire:key="kategori-'.$ids[1].'"');
    }
}
