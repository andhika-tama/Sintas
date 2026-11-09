<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Irban;
use App\Models\Spt;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** Simulates a page that is not built yet by removing its route (Navigation hides items without a route). */
    private function dropRoute(string $name): void
    {
        $sisa = new RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if ($r->getName() !== $name) {
                $sisa->add($r);
            }
        }
        Route::setRoutes($sisa);
    }

    public function test_admin_sidebar_marks_active_item_and_shows_existing_master_data_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('aria-label="Navigasi utama"', false)
            ->assertSee('Master Data')
            ->assertSee('href="'.route('admin.kode-arsip').'"', false)
            ->assertSee('href="'.route('admin.impor').'"', false)
            ->assertSee('Pengaturan Sistem')
            ->assertSee('Ciutkan menu')
            ->assertSee('aria-label="Buka menu"', false)
            ->getContent();

        // aria-current only on the Dasbor link.
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#<a[^>]+href="'.preg_quote(route('admin.dashboard'), '#').'"[^>]*aria-current="page"#', $html);
        $this->assertStringContainsString('href="'.route('admin.log').'"', $html);
    }

    /** The edit form marks "SPT Saya" (server render and the client pattern used after wire:navigate); the new-SPT form keeps its own item. */
    public function test_ubah_spt_marks_spt_saya_in_the_sidebar(): void
    {
        $irban = Irban::factory()->create();
        $user = User::factory()->irban()->create(['irban_id' => $irban->id]);
        $spt = Spt::factory()->create(['irban_id' => $irban->id, 'dibuat_oleh' => $user->id]);
        // (?:(?!</a>).)*? instead of [^>]*: the x-init of items with `aktif` routes contains `=>`.
        $sptSaya = '#<a href="'.preg_quote(route('irban.spt'), '#').'"(?:(?!</a>).)*?aria-current="page"#s';

        $html = $this->actingAs($user)->get(route('irban.spt.ubah', $spt))->assertOk()->getContent();
        $this->assertMatchesRegularExpression($sptSaya, $html);
        $this->assertSame(1, preg_match_all('#data-nav-link(?:(?!</a>).)*?aria-current="page"#s', $html));

        $item = collect(Navigation::links(Role::Irban))->firstWhere('route', 'irban.spt');
        $path = parse_url(route('irban.spt.ubah', $spt), PHP_URL_PATH);
        $this->assertTrue(collect(Navigation::pola($item, true))->contains(fn ($r) => preg_match('#'.$r.'#', $path)));

        $baru = $this->get(route('irban.spt.ajukan'))->assertOk()->getContent(); // control
        $this->assertDoesNotMatchRegularExpression($sptSaya, $baru);
        $this->assertMatchesRegularExpression('#<a[^>]+href="'.preg_quote(route('irban.spt.ajukan'), '#').'"[^>]*aria-current="page"#', $baru);
    }

    /** `fokus-ke` is heard once, by the layout, so the Komentar child no longer depends on the Detail page's own listener. */
    public function test_layout_owns_the_single_fokus_ke_listener(): void
    {
        $irban = Irban::factory()->create();
        $user = User::factory()->irban()->create(['irban_id' => $irban->id]);
        $spt = Spt::factory()->diterima()->create(['irban_id' => $irban->id]);

        $html = $this->actingAs($user)->get(route('spt.show', $spt))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'x-on:fokus-ke.window='));
        $this->assertMatchesRegularExpression('#<main id="konten" x-data x-on:fokus-ke\.window=#', $html);
        $this->assertStringContainsString('wire:name="spt.komentar"', $html); // control: the Komentar child is on the page
    }

    public function test_empty_groups_are_dropped(): void
    {
        $admin = User::factory()->admin()->create();
        // With every real route present, Konten Beranda (labelled) is shown.
        $this->assertContains('Konten Beranda', array_column(Navigation::groups(Role::Admin), 'label'));
        $this->actingAs($admin)->get('/admin')->assertSee('Konten Beranda');

        // Take away the routes of that labelled group: it must disappear with its label, while Master Data
        // (some routes left) stays. Holds no matter which routes later phases add.
        $dibuang = ['admin.slide', 'admin.kontak', 'admin.latar-masuk'];
        $sisa = new RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if (! in_array($r->getName(), $dibuang, true)) {
                $sisa->add($r);
            }
        }
        Route::setRoutes($sisa);

        $groups = Navigation::groups(Role::Admin);
        $labels = array_column($groups, 'label');
        $this->assertNotContains('Konten Beranda', $labels);
        $this->assertContains('Master Data', $labels);
        foreach ($groups as $group) {
            $this->assertNotEmpty($group['items']);
            foreach ($group['items'] as $item) {
                $this->assertTrue(Route::has($item['route']));
            }
        }
        $this->actingAs($admin)->get('/admin/pengaturan')->assertOk()->assertDontSee('Konten Beranda')->assertDontSee('Slide Beranda')->assertSee('Master Data');
    }

    public function test_other_roles_see_only_dasbor(): void
    {
        $paths = ['pimpinan' => '/pimpinan', 'irban' => '/irban', 'bagianUmum' => '/bagian-umum'];

        foreach ($paths as $state => $path) {
            $user = User::factory()->{$state}()->create($state === 'irban' ? ['irban_id' => Irban::factory()->create()->id] : []);
            $this->actingAs($user)->get($path)->assertOk()
                ->assertSee('Dasbor')
                ->assertDontSee('Master Data')
                ->assertDontSee(route('admin.impor'));
        }
        foreach ([Role::Pimpinan, Role::Irban, Role::BagianUmum] as $role) {
            $this->assertSame(['Dasbor'], array_column(Navigation::groups($role)[0]['items'], 'label'));
            $this->assertCount(2, Navigation::groups($role));
        }
        $this->assertSame(['Kotak Masuk Pengajuan', 'Register Nomor SPT'], array_column(Navigation::groups(Role::BagianUmum)[1]['items'], 'label'));
        $this->assertSame(['SPT Saya', 'Ajukan Nomor SPT', 'Jadwal PKPT', 'Rekap Saya'], array_column(Navigation::groups(Role::Irban)[1]['items'], 'label'));
        $this->assertSame(['Monitoring SPT', 'Rekap Triwulanan', 'Perubahan PKPT', 'Log Aktivitas'], array_column(Navigation::groups(Role::Pimpinan)[1]['items'], 'label'));
    }

    public function test_admin_dashboard_quick_links_come_from_navigation(): void
    {
        $admin = User::factory()->admin()->create();
        $asli = Route::getRoutes();
        $this->dropRoute('admin.wilayah-irban'); // page "not built yet": no quick link
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Akses cepat')->assertDontSee(url('/admin/wilayah-irban'));

        Route::setRoutes($asli); // route back
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Akses cepat')
            ->assertSeeInOrder(['Akses cepat', 'Pengaturan Sistem', url('/admin/impor')], false)
            ->assertSee(url('/admin/wilayah-irban'), false);
    }

    public function test_breadcrumbs_render_when_passed(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $html = (string) $this->blade(
            '<x-layouts::app title="X" :breadcrumbs="[\'Dasbor\' => \'/admin\', \'Master Data\' => null, \'Pegawai\' => null]">isi</x-layouts::app>'
        );
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"')); // only the last crumb (no nav item is active here)
        $this->assertMatchesRegularExpression('#<span[^>]*aria-current="page"[^>]*>Pegawai</span>#', $html);
    }

    public function test_layout_renders_toast_stack(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()
            ->assertSee('x-on:toast.window', false);
    }

    public function test_flagged_user_cannot_use_livewire_update_endpoint(): void
    {
        $user = User::factory()->admin()->create(['wajib_ganti_password' => true]);

        $this->actingAs($user)
            ->postJson(route('default-livewire.update'), ['components' => []], ['X-Livewire' => 'true'])
            ->assertRedirect('/perbarui-kata-sandi');
    }
}
