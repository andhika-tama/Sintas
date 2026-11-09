<?php

namespace Tests\Feature\MasterData;

use App\Enums\Role;
use App\Livewire\Admin\Irban as Page;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Pegawai;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\IrbanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class IrbanTest extends TestCase
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
        $this->get('/admin/irban')->assertRedirect('/masuk');
        $this->actingAs(User::factory()->irban()->create())->get('/admin/irban')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/irban')->assertOk()->assertSee('Irban');
        Livewire::actingAs(User::factory()->irban()->create())->test(Page::class)->assertForbidden();
    }

    public function test_create_with_tembusan_list(): void
    {
        $this->page()->call('create')->assertSet('open', true)
            ->set('nama', 'Irban I')->set('kodeNomor', 'INSPEKTORAT-I')->set('emailNotifikasi', 'a@x.id')
            ->call('addTembusan')->set('tembusan.0', 'b@x.id')->call('addTembusan')->set('tembusan.1', 'c@x.id')
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertDispatched('toast');
        $i = Irban::firstOrFail();
        $this->assertSame(['b@x.id', 'c@x.id'], $i->email_tembusan);
        $this->assertSame('a@x.id', $i->email_notifikasi);
    }

    public function test_empty_tembusan_is_stored_as_null_and_blank_rows_dropped(): void
    {
        $this->page()->call('create')->set('nama', 'Irban I')->set('kodeNomor', 'K1')
            ->call('addTembusan')->call('save')->assertHasNoErrors();
        $this->assertNull(Irban::firstOrFail()->email_tembusan);
    }

    public function test_remove_tembusan_row(): void
    {
        $this->page()->call('create')->call('addTembusan')->call('addTembusan')->set('tembusan.0', 'a@x.id')->set('tembusan.1', 'b@x.id')
            ->call('removeTembusan', 0)->assertSet('tembusan', ['b@x.id']);
    }

    public function test_email_validation_and_duplicates(): void
    {
        $this->page()->call('create')->set('nama', 'X')->set('kodeNomor', 'K')->set('emailNotifikasi', 'bukan-email')
            ->call('addTembusan')->set('tembusan.0', 'salah')->call('save')
            ->assertHasErrors(['emailNotifikasi', 'tembusan.0']);
        $this->page()->call('create')->set('nama', 'X')->set('kodeNomor', 'K')
            ->call('addTembusan')->call('addTembusan')->set('tembusan.0', 'a@x.id')->set('tembusan.1', 'A@x.id')->call('save')
            ->assertHasErrors('tembusan.0');
        $this->assertSame(0, Irban::count());
    }

    public function test_nama_and_kode_required_and_unique(): void
    {
        $o = Irban::factory()->create();
        $this->page()->call('create')->call('save')->assertHasErrors(['nama', 'kodeNomor']);
        $this->page()->call('create')->set('nama', $o->nama)->set('kodeNomor', $o->kode_nomor)->call('save')->assertHasErrors(['nama', 'kodeNomor']);
        $this->page()->call('edit', $o->id)->set('nama', 'Baru')->call('save')->assertHasNoErrors();
        $this->assertSame('Baru', $o->fresh()->nama);
    }

    public function test_edit_loads_tembusan(): void
    {
        $o = Irban::factory()->create(['email_tembusan' => ['a@x.id', 'b@x.id']]);
        $this->page()->call('edit', $o->id)->assertSet('tembusan', ['a@x.id', 'b@x.id']);
    }

    public function test_delete_unreferenced(): void
    {
        $o = Irban::factory()->create();
        $this->page()->call('confirmDelete', $o->id)->assertSet('deleteOpen', true)->call('delete')->assertDispatched('toast', type: 'success', message: "Irban {$o->nama} dihapus.");
        $this->assertModelMissing($o);
    }

    public function test_delete_blocked_when_user_or_tahunan_references(): void
    {
        $withUser = Irban::factory()->create();
        User::factory()->irban()->create()->forceFill(['irban_id' => $withUser->id])->save();
        $withTahunan = IrbanTahunan::factory()->create()->irban;
        foreach ([$withUser, $withTahunan] as $o) {
            $this->assertTrue($o->isReferenced());
            $this->page()->call('confirmDelete', $o->id)->call('delete')->assertDispatched('toast', type: 'error');
            $this->assertModelExists($o);
        }
    }

    public function test_every_action_rejects_demoted_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $o = Irban::factory()->create(['nama' => 'Asli']);
        foreach ([['create'], ['edit', $o->id], ['save'], ['addTembusan'], ['removeTembusan', 0], ['confirmDelete', $o->id], ['delete']] as $call) {
            [$method, $arg] = $call + [null, null];
            $admin->role = Role::Admin;
            $c = Livewire::actingAs($admin)->test(Page::class)->call('edit', $o->id)->set('nama', 'Diubah')->call('confirmDelete', $o->id);
            $admin->role = Role::Irban;
            $c->call($method, ...($arg !== null ? [$arg] : []))->assertForbidden();
        }
        $this->assertSame('Asli', $o->fresh()->nama);
        $this->assertModelExists($o);
    }

    public function test_seeder_creates_six_irban_and_links_irban1(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // idempotent
        $this->assertSame(6, Irban::count());
        $this->assertSame(['Irban I', 'Irban II', 'Irban III', 'Irban IV', 'Irban Khusus', 'Sekretariat'], Irban::orderBy('id')->pluck('nama')->all());
        $this->assertSame('INSPEKTORAT-III', Irban::where('nama', 'Irban III')->value('kode_nomor'));
        $this->assertSame('INSPEKTORAT-IRSUS', Irban::where('nama', 'Irban Khusus')->value('kode_nomor'));
        $this->assertSame('INSPEKTORAT-V', Irban::where('nama', 'Sekretariat')->value('kode_nomor'));
        $this->assertSame(Irban::where('nama', 'Irban I')->value('id'), User::where('username', 'irban1')->value('irban_id'));
    }

    public function test_seeder_runs_in_production(): void
    {
        // Fase 11 Task 7 (E11): the Irban are reference data, also on a production install.
        $this->app->detectEnvironment(fn () => 'production');
        (new IrbanSeeder)->run();
        $this->assertSame(6, Irban::count());
    }

    public function test_users_irban_id_has_foreign_key(): void
    {
        Schema::enableForeignKeyConstraints();
        $user = User::factory()->irban()->create();
        $this->expectException(QueryException::class);
        $user->forceFill(['irban_id' => 999])->save();
    }

    public function test_deleting_irban_nulls_user_irban_id(): void
    {
        Schema::enableForeignKeyConstraints();
        $o = Irban::factory()->create();
        $u = User::factory()->irban()->create();
        $u->forceFill(['irban_id' => $o->id])->save();
        $o->delete();
        $this->assertNull($u->fresh()->irban_id);
    }

    public function test_pegawai_referenced_by_irban_blocks_delete_with_toast(): void
    {
        $anggota = Pegawai::factory()->create();
        $ppj = Pegawai::factory()->create();
        $it = IrbanTahunan::factory()->create(['ppj_pegawai_id' => $ppj->id]);
        $it->anggota()->attach($anggota->id);
        $free = Pegawai::factory()->create();

        $this->assertTrue($anggota->isReferenced());
        $this->assertTrue($ppj->isReferenced());
        $this->assertFalse($free->isReferenced());

        foreach ([$anggota, $ppj] as $p) {
            Livewire::actingAs(User::factory()->admin()->create())->test(\App\Livewire\Admin\Pegawai::class)
                ->call('confirmDelete', $p->id)->call('delete')->assertDispatched('toast', type: 'error');
            $this->assertModelExists($p);
        }
    }
}
