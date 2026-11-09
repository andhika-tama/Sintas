<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Irban;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PATHS = [
        'admin' => ['/admin', 'Dasbor Admin'],
        'pimpinan' => ['/pimpinan', 'Dasbor Pimpinan'],
        'irban' => ['/irban', 'Dasbor Irban'],
        'bagianUmum' => ['/bagian-umum', 'Dasbor Bagian Umum'],
    ];

    public function test_each_role_is_redirected_to_and_sees_own_dashboard(): void
    {
        foreach (self::PATHS as $state => [$path, $h1]) {
            $user = User::factory()->{$state}()->create($state === 'irban' ? ['irban_id' => Irban::factory()->create()->id] : []);
            $this->actingAs($user)->get('/dashboard')->assertRedirect($path);
            $this->withoutVite()->actingAs($user)->get($path)->assertOk()->assertSee($h1);
        }
    }

    public function test_other_dashboards_are_forbidden(): void
    {
        foreach (self::PATHS as $state => [$own]) {
            $user = User::factory()->{$state}()->create();
            foreach (self::PATHS as [$path]) {
                if ($path !== $own) {
                    $this->actingAs($user)->get($path)->assertForbidden();
                }
            }
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        foreach (array_merge(['/dashboard'], array_column(self::PATHS, 0)) as $path) {
            $this->get($path)->assertRedirect('/masuk');
        }
    }

    public function test_factory_default_is_irban_with_unique_username(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $this->assertSame(Role::Irban, $a->role);
        $this->assertNotSame($a->username, $b->username);
    }

    public function test_seeder_creates_demo_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Fase 11 Task 7 (E11): irban2–irban4, irsus (Irban Khusus) and sekretaris joined the four original accounts.
        $this->assertSame(10, User::count());
        $expected = ['admin' => Role::Admin, 'pimpinan' => Role::Pimpinan, 'irban1' => Role::Irban, 'bagianumum' => Role::BagianUmum,
            'irban2' => Role::Irban, 'irban3' => Role::Irban, 'irban4' => Role::Irban, 'irsus' => Role::Irban, 'sekretaris' => Role::Pimpinan, 'sekretariat' => Role::Irban];
        foreach ($expected as $username => $role) {
            $u = User::where('username', $username)->firstOrFail();
            $this->assertSame($role, $u->role);
            $this->assertSame($role === Role::Irban, $u->irban_id !== null, $username);
            $this->assertSame("{$username}@sintas.test", $u->email);
            $this->assertNotSame('password', $u->password);
            $this->assertTrue(Hash::check('password', $u->password));
        }
    }

    public function test_every_dashboard_has_header_with_account_role_logout_and_skip_link(): void
    {
        $labels = ['admin' => 'Admin', 'pimpinan' => 'Pimpinan', 'irban' => 'Irban', 'bagianUmum' => 'Bagian Umum'];
        foreach (self::PATHS as $state => [$path]) {
            $user = User::factory()->{$state}()->create(['name' => 'Tuan Uji', 'irban_id' => $state === 'irban' ? Irban::factory()->create()->id : null]);
            $this->withoutVite()->actingAs($user)->get($path)->assertOk()
                ->assertSee('Tuan Uji')
                ->assertSee('<span class="block text-xs text-neutral-600">'.$labels[$state].'</span>', false)
                ->assertSee('<form method="POST" action="'.route('logout').'"', false)
                ->assertSee('Keluar')
                ->assertSee('href="#konten"', false)
                ->assertSee('<main id="konten"', false);
        }
    }
}
