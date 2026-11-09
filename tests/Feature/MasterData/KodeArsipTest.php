<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\SaveKodeArsip;
use App\Enums\Role;
use App\Livewire\Admin\KodeArsip as Page;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class KodeArsipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function page()
    {
        return Livewire::actingAs(User::factory()->admin()->create())->test(Page::class);
    }

    public function test_only_admin_can_open_page(): void
    {
        $this->get('/admin/kode-arsip')->assertRedirect('/masuk');
        $this->actingAs(User::factory()->irban()->create())->get('/admin/kode-arsip')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/kode-arsip')->assertOk()->assertSee('Kode Arsip')->assertSee('Pengawasan Umum');
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class)->assertForbidden();
    }

    public function test_admin_creates_updates_and_deletes(): void
    {
        $this->page()->call('create')->set('kode', '700.1')->set('nama', 'Pengawasan Khusus')->call('save')->assertHasNoErrors()->assertDispatched('toast');
        $k = KodeArsip::where('kode', '700.1')->firstOrFail();
        $this->assertSame('Pengawasan Khusus', $k->nama);

        $this->page()->call('edit', $k->id)->assertSet('kode', '700.1')->set('nama', 'Khusus')->call('save')->assertHasNoErrors();
        $this->assertSame('Khusus', $k->fresh()->nama);
        // Keeping its own kode is not a duplicate.
        $this->assertSame('700.1', $k->fresh()->kode);

        $this->page()->call('confirmDelete', $k->id)->assertSet('deleteOpen', true)->call('delete')->assertDispatched('toast', type: 'success', message: 'Kode arsip 700.1 dihapus.');
        $this->assertNull(KodeArsip::find($k->id));
    }

    public function test_validation_messages(): void
    {
        $this->page()->call('create')->set('kode', '')->set('nama', 'X')->call('save')->assertHasErrors(['kode' => 'required']);
        $this->page()->call('create')->set('kode', '')->set('nama', 'X')->call('save')->assertSee('Isi kode arsip.');
        $this->page()->call('create')->set('kode', '70/0')->set('nama', 'X')->call('save')->assertSee('Kode arsip hanya huruf, angka, dan titik.');
        $this->page()->call('create')->set('kode', str_repeat('7', 21))->set('nama', 'X')->call('save')->assertSee('Kode arsip maksimal 20 karakter.');
        $this->page()->call('create')->set('kode', '700')->set('nama', 'X')->call('save')->assertSee('Kode arsip sudah ada.');
        $this->page()->call('create')->set('kode', '710')->set('nama', '')->call('save')->assertSee('Isi nama kode arsip.');
        $this->page()->call('create')->set('kode', '710')->set('nama', str_repeat('a', 101))->call('save')->assertSee('Nama maksimal 100 karakter.');
        $this->assertSame(3, KodeArsip::count());
    }

    /** Review akhir 1.2: `$` also matches before a final newline; `\z` does not. */
    public function test_trailing_newline_is_rejected(): void
    {
        try {
            app(SaveKodeArsip::class)->handle(['kode' => "700\n", 'nama' => 'X']);
            $this->fail('"700\n" must be rejected.');
        } catch (ValidationException $e) {
            $this->assertContains('Kode arsip hanya huruf, angka, dan titik.', $e->errors()['kode'] ?? []);
        }
    }

    public function test_delete_of_used_kode_is_refused_with_toast(): void
    {
        $k = KodeArsip::where('kode', '700')->firstOrFail();
        Spt::factory()->create(['kode_arsip_id' => $k->id]);
        $this->page()->call('confirmDelete', $k->id)->call('delete')
            ->assertDispatched('toast', type: 'error', message: 'Kode arsip sudah dipakai SPT; tidak bisa dihapus.');
        $this->assertNotNull($k->fresh());
    }

    public function test_every_action_rejects_demoted_admin(): void
    {
        $k = KodeArsip::where('kode', '700')->firstOrFail();
        $admin = User::factory()->admin()->create();
        foreach ([['create'], ['edit', $k->id], ['save'], ['confirmDelete', $k->id], ['delete']] as $call) {
            [$method, $arg] = $call + [null, null];
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Page::class)->call('edit', $k->id)->set('nama', 'Diubah')->call('confirmDelete', $k->id);
            $admin->role = Role::Irban;
            $c->call($method, ...($arg !== null ? [$arg] : []))->assertForbidden();
        }
        $this->assertSame('Pengawasan Umum', $k->fresh()->nama);
    }
}
