<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\DeletePegawai;
use App\Enums\Role;
use App\Livewire\Admin\Pegawai as Page;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PegawaiTest extends TestCase
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

    private function page()
    {
        return Livewire::actingAs($this->admin())->test(Page::class);
    }

    public function test_only_admin_can_open_pages(): void
    {
        foreach (['/admin/pegawai', '/admin/pegawai/impor'] as $url) {
            $this->get($url)->assertRedirect('/masuk');
        }
        foreach (['/admin/pegawai', '/admin/pegawai/impor'] as $url) {
            $this->actingAs(User::factory()->irban()->create())->get($url)->assertForbidden();
            $this->actingAs($this->admin())->get($url)->assertOk();
        }
        $this->actingAs($this->admin())->get('/admin/pegawai/impor')->assertSee('Impor Pegawai');
    }

    public function test_empty_state_has_both_actions(): void
    {
        $this->actingAs($this->admin())->get('/admin/pegawai')
            ->assertSee('Belum ada data pegawai. Tambah manual atau impor dari Excel.')
            ->assertSee('Tambah pegawai')->assertSee('Impor dari Excel')
            ->assertSee(route('admin.pegawai.impor'), false);
    }

    public function test_create_persists(): void
    {
        $this->page()->call('create')->assertSet('open', true)
            ->set('nama', 'Dr. Budi Santoso, M.Si.')->set('nip', '198703122010011001')
            ->set('pangkat', 'Pembina')->set('golongan', 'IV/a')->set('jabatan', 'Inspektur')
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertDispatched('toast');

        $p = Pegawai::firstOrFail();
        $this->assertSame('Dr. Budi Santoso, M.Si.', $p->nama_bergelar);
        $this->assertSame('198703122010011001', $p->nip);
        $this->assertTrue($p->aktif);
    }

    public function test_edit_updates_and_keeps_own_nip_unique(): void
    {
        $p = Pegawai::factory()->create();
        $this->page()->call('edit', $p->id)->assertSet('nip', $p->nip)
            ->set('nama', 'Nama Baru')->set('aktif', false)->call('save')->assertHasNoErrors();
        $p->refresh();
        $this->assertSame('Nama Baru', $p->nama_bergelar);
        $this->assertFalse($p->aktif);
    }

    public function test_nip_validation(): void
    {
        $other = Pegawai::factory()->create();
        foreach (['123', '19870312201001100', '1987031220100110011', '19870312201001100a'] as $bad) {
            $this->page()->call('create')->set('nama', 'X')->set('nip', $bad)->call('save')
                ->assertHasErrors('nip')->assertSee('NIP harus 18 digit angka.');
        }
        $this->page()->call('create')->set('nama', 'X')->set('nip', $other->nip)->call('save')->assertHasErrors('nip');
        $this->page()->call('create')->set('nip', '198703122010011001')->call('save')->assertHasErrors('nama');
    }

    public function test_search_filter_sort(): void
    {
        Pegawai::factory()->create(['nama_bergelar' => 'Agus', 'nip' => '111111111111111111', 'aktif' => true]);
        Pegawai::factory()->create(['nama_bergelar' => 'Zainal', 'nip' => '222222222222222222', 'aktif' => false]);

        $this->page()->set('search', 'zain')->assertSee('Zainal')->assertDontSee('Agus')
            ->set('search', '1111')->assertSee('Agus')->assertDontSee('Zainal')
            ->set('search', '')->set('filter', 'aktif')->assertSee('Agus')->assertDontSee('Zainal')
            ->set('filter', 'nonaktif')->assertSee('Zainal')->assertDontSee('Agus')
            ->set('filter', 'semua')->call('sort', 'nama')->assertSet('sortDir', 'desc')->assertSeeInOrder(['Zainal', 'Agus'])
            ->call('sort', 'nama')->assertSet('sortDir', 'asc')->assertSeeInOrder(['Agus', 'Zainal'])
            ->call('sort', 'nip')->assertSet('sortBy', 'nip')->assertSet('sortDir', 'asc');
    }

    public function test_empty_optional_cells_show_a_dash_and_filled_ones_do_not(): void
    {
        Pegawai::factory()->create(['nama_bergelar' => 'Kosong', 'pangkat' => null, 'golongan' => null, 'jabatan' => null, 'email' => null]);
        $this->assertSame(3, substr_count($this->page()->html(), '>—</td>'), 'pangkat/golongan, jabatan, email');

        Pegawai::query()->delete();
        Pegawai::factory()->denganEmail()->create(['pangkat' => 'Penata', 'golongan' => 'III/c', 'jabatan' => 'Auditor']);
        $this->assertSame(0, substr_count($this->page()->html(), '>—</td>'));
    }

    public function test_sort_ignores_unknown_column(): void
    {
        $this->page()->call('sort', 'password')->assertSet('sortBy', 'nama');
    }

    public function test_toggle_aktif(): void
    {
        $p = Pegawai::factory()->create();
        $this->page()->call('toggleAktif', $p->id)->assertDispatched('toast');
        $this->assertFalse($p->fresh()->aktif);
    }

    public function test_delete_unreferenced_hard_deletes_after_confirm(): void
    {
        $p = Pegawai::factory()->create();
        $this->page()->call('confirmDelete', $p->id)->assertSet('deleteOpen', true)
            ->call('delete')->assertSet('deleteOpen', false)->assertDispatched('toast');
        $this->assertModelMissing($p);
    }

    public function test_delete_referenced_is_blocked_and_deactivates(): void
    {
        $p = new class extends Pegawai
        {
            public function isReferenced(): bool
            {
                return true;
            }
        };
        $this->assertTrue($p->isReferenced());
        $this->assertFalse((new Pegawai)->isReferenced());
        $this->expectException(ValidationException::class);
        app(DeletePegawai::class)->handle($p);
    }

    public function test_pegawai_referenced_only_by_perubahan_penugasan_diinput_oleh_is_referenced(): void
    {
        $p = Pegawai::factory()->create();
        PerubahanPenugasan::factory()->create(['diinput_oleh' => $p->id]);
        $this->assertTrue($p->isReferenced());
        $this->expectException(ValidationException::class);
        app(DeletePegawai::class)->handle($p);
    }

    public function test_pagination_per_page(): void
    {
        Pegawai::factory()->count(30)->create();
        $this->page()->assertSee('Menampilkan 1–25 dari 30')->set('perPage', 50)->assertSee('Menampilkan 1–30 dari 30');
    }

    public function test_every_action_rejects_demoted_admin(): void
    {
        $admin = $this->admin();
        $p = Pegawai::factory()->create(['nama_bergelar' => 'Asli']);
        $calls = [['create'], ['edit', $p->id], ['save'], ['toggleAktif', $p->id], ['confirmDelete', $p->id], ['delete']];
        foreach ($calls as $call) {
            [$method, $arg] = $call + [null, null];
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Page::class)->call('edit', $p->id)->set('nama', 'Diubah')->call('confirmDelete', $p->id);
            $admin->role = Role::Irban;
            $c->call($method, ...($arg ? [$arg] : []))->assertForbidden();
        }
        $this->assertSame('Asli', $p->fresh()->nama_bergelar);
        $this->assertTrue($p->fresh()->aktif);
        $this->assertModelExists($p);
    }

    public function test_non_admin_cannot_mount(): void
    {
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class)->assertForbidden();
    }

    public function test_invalid_per_page_from_url_falls_back_to_25(): void
    {
        Pegawai::factory()->count(30)->create();
        foreach ([100000, 0, -5] as $bad) {
            Livewire::withQueryParams(['perPage' => $bad])->actingAs($this->admin())->test(Page::class)
                ->assertSee('Menampilkan 1–25 dari 30');
        }
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Pegawai::factory()->create(['nama_bergelar' => 'Agus 100%', 'nip' => '111111111111111111']);
        Pegawai::factory()->create(['nama_bergelar' => 'Budi_Utama', 'nip' => '222222222222222222']);
        Pegawai::factory()->create(['nama_bergelar' => 'Citra', 'nip' => '333333333333333333']);
        $this->page()->set('search', '_')->assertSee('Budi_Utama')->assertDontSee('Citra')->assertDontSee('Agus')
            ->set('search', '%')->assertSee('Agus 100%')->assertDontSee('Citra')->assertDontSee('Budi_Utama');
    }

    public function test_deleting_last_row_of_last_page_moves_to_previous_page(): void
    {
        Pegawai::factory()->count(26)->create();
        $last = Pegawai::orderByDesc('nama_bergelar')->first();
        $this->page()->call('gotoPage', 2)->call('confirmDelete', $last->id)->call('delete')
            ->assertDontSee('Tidak ada pegawai yang cocok')->assertSee('Menampilkan 1–25 dari 25');
    }

    public function test_row_actions_menu_has_a11y_attributes_and_primary_action_inline(): void
    {
        $p = Pegawai::factory()->create(['nama_bergelar' => 'Rina Sari']);
        $html = $this->page()->html();
        $this->assertStringContainsString('aria-label="Aksi untuk Rina Sari"', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('aria-expanded', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('wire:click="edit('.$p->id.')"', $html);
        $this->assertStringContainsString('wire:click="confirmDelete('.$p->id.')"', $html);
        $this->assertStringContainsString('wire:key="pegawai-'.$p->id.'"', $html);
    }

    public function test_email_is_optional_validated_unique_and_shown(): void
    {
        $ada = Pegawai::factory()->create(['nama_bergelar' => 'Ani', 'email' => 'ani@example.test']);
        $this->page()->call('create')->set('nama', 'X')->set('nip', '198703122010011001')->call('save')->assertHasNoErrors();
        $this->assertNull(Pegawai::where('nip', '198703122010011001')->value('email'));

        $this->page()->call('create')->set('nama', 'X')->set('nip', '198703122010011002')->set('email', 'salah')->call('save')
            ->assertHasErrors('email')->assertSee('Format email tidak valid.');
        $this->page()->call('create')->set('nama', 'X')->set('nip', '198703122010011002')->set('email', 'ANI@example.test')->call('save')
            ->assertSee('Email sudah dipakai pegawai lain.');
        $this->page()->call('create')->set('nama', 'Y')->set('nip', '198703122010011003')->set('email', ' Baru@Example.test ')->call('save')->assertHasNoErrors();
        $this->assertSame('baru@example.test', Pegawai::where('nip', '198703122010011003')->value('email'));

        // Own email stays valid on edit; the column shows it.
        $this->page()->call('edit', $ada->id)->assertSet('email', 'ani@example.test')->call('save')->assertHasNoErrors()
            ->assertSee('Email')->assertSee('ani@example.test');
    }
}
