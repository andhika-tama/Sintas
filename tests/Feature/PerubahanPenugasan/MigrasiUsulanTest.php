<?php

namespace Tests\Feature\PerubahanPenugasan;

use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahanPenugasan;
use App\Livewire\LogAktivitas;
use App\Livewire\Notifikasi\Lonceng;
use App\Models\Aktivitas;
use App\Models\Irban;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\User;
use App\Support\LabelAktivitas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** 1.2 Task 8 (K11): pkpt_usulan goes; only open `geser` usulan become Diajukan Perubahan Penugasan. Old bell and log rows still render. */
class MigrasiUsulanTest extends TestCase
{
    use RefreshDatabase;

    private const KELAS_LAMA = 'App\Models\PkptUsulan';

    private function migrasi(): object
    {
        return require database_path('migrations/2026_12_20_000005_hapus_pkpt_usulan.php');
    }

    /**
     * MySQL/MariaDB commit the RefreshDatabase transaction on DDL, so this test's rows survive the rollback: the test restores the
     * database itself with migrate:fresh after that rollback, whatever trait the next test uses. SQLite DDL is transactional.
     */
    private function bersihkanSetelahDdl(): void
    {
        $this->beforeApplicationDestroyed(function () {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                Artisan::call('migrate:fresh', $this->migrateFreshUsing());
                RefreshDatabaseState::$migrated = true;
            }
        });
    }

    public function test_table_and_column_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('pkpt_usulan'));
        $this->assertFalse(Schema::hasColumn('pkpt_perubahan_item', 'usulan_id'));
    }

    public function test_only_open_geser_usulan_become_diajukan_perubahan_penugasan(): void
    {
        $this->bersihkanSetelahDdl();
        $m = $this->migrasi();
        $m->down(); // the empty pre-Task 8 schema

        $irban = Irban::factory()->create();
        $user = User::factory()->irban()->create(['irban_id' => $irban->id]);
        $pegawai = Pegawai::factory()->create();
        $pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $item = fn (array $x = []) => PkptItem::factory()->create($x + ['pkpt_id' => $pkpt->id, 'irban_id' => $irban->id]);
        [$a, $b, $c, $d] = [$item(), $item(), $item(), $item()];
        $dihapus = $item(['status' => StatusItemPkpt::Dihapus]);
        $sudah = PerubahanPenugasan::factory()->create(['pkpt_item_id' => $d->id, 'irban_id' => $irban->id]);
        $paket = PkptPerubahan::factory()->create(['pkpt_id' => $pkpt->id]);

        $usulan = fn (PkptItem $i, array $x = []) => DB::table('pkpt_usulan')->insertGetId($x + [
            'pkpt_id' => $pkpt->id, 'irban_id' => $irban->id, 'pkpt_item_id' => $i->id, 'aksi_usulan' => 'geser',
            'tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-15', 'alasan' => 'Alasan '.$i->id, 'diajukan_oleh' => $user->id,
            'diinput_oleh' => null, 'status' => 'terbuka', 'created_at' => '2026-02-10 09:00:00', 'updated_at' => '2026-02-11 10:00:00']);
        $usulan($a, ['diinput_oleh' => $pegawai->id, 'alasan' => 'OPD sedang diaudit BPK.']);
        $usulan($b, ['status' => 'ditutup', 'catatan_penutupan' => 'Tidak perlu.']);
        $usulan($b, ['aksi_usulan' => 'hapus', 'tanggal_mulai' => null, 'tanggal_selesai' => null]);
        $ditampung = $usulan($b, ['status' => 'ditampung', 'perubahan_id' => $paket->id]);
        $usulan($b, ['status' => 'diterapkan']);
        $usulan($c, ['alasan' => 'Lama']);
        $usulan($c, ['alasan' => 'Terbaru', 'tanggal_mulai' => '2026-06-01', 'tanggal_selesai' => '2026-06-05']);
        $usulan($d); // R2: d already has a Diajukan request
        $usulan($dihapus);
        // Review akhir: dead on arrival (moved to another irban, or the item already has the dates) and a non-geser usulan with dates.
        $usulan($item(['irban_id' => Irban::factory()->create()->id]));
        $usulan($item(['tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-15']));
        $usulan($item(), ['aksi_usulan' => 'lainnya']);
        DB::table('pkpt_perubahan_item')->insert(['perubahan_id' => $paket->id, 'aksi' => 'ubah', 'pkpt_item_id' => $b->id, 'data_baru' => '{}',
            'usulan_id' => $ditampung, 'urutan' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $m->up();

        $this->assertFalse(Schema::hasTable('pkpt_usulan'));
        $this->assertFalse(Schema::hasColumn('pkpt_perubahan_item', 'usulan_id'));
        $this->assertSame(1, DB::table('pkpt_perubahan_item')->count());

        $baru = PerubahanPenugasan::whereKeyNot($sudah->id)->orderBy('id')->get();
        $this->assertSame([$a->id, $c->id], $baru->pluck('pkpt_item_id')->all());
        $p = $baru->first();
        $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->status);
        $this->assertSame([$irban->id, '2026-05-04', '2026-05-15', 'OPD sedang diaudit BPK.', $user->id, $pegawai->id],
            [$p->irban_id, $p->tanggal_mulai->toDateString(), $p->tanggal_selesai->toDateString(), $p->alasan, $p->diajukan_oleh, $p->diinput_oleh]);
        $this->assertSame(['2026-02-10 09:00:00', '2026-02-11 10:00:00'], [$p->created_at->toDateTimeString(), $p->updated_at->toDateTimeString()]);
        $this->assertNull($p->diputuskan_oleh);
        $this->assertSame(['Terbaru', '2026-06-01'], [$baru->last()->alasan, $baru->last()->tanggal_mulai->toDateString()]);
        $this->assertSame(1, PerubahanPenugasan::where('pkpt_item_id', $d->id)->count());
    }

    /** Review Focus 1: notifications of the removed flow keep their icon and still open in the bell. */
    public function test_old_usulan_notifications_render_in_the_bell(): void
    {
        $user = User::factory()->admin()->create();
        foreach (['usulan_perubahan_baru' => 'UsulanPerubahanBaru', 'usulan_perubahan_diterapkan' => 'UsulanPerubahanDiputuskan',
            'usulan_perubahan_ditutup' => 'UsulanPerubahanDiputuskan'] as $jenis => $kelas) {
            $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\Notifications\\'.$kelas,
                'data' => ['jenis' => $jenis, 'judul' => 'Lama '.$jenis, 'konteks' => 'PKPT 2026', 'url' => '/irban/pkpt?tahun=2026&tampilan=usulan', 'catatan' => null]]);
        }

        $c = Livewire::actingAs($user)->test(Lonceng::class)->set('terbuka', true)
            ->assertSee('Lama usulan_perubahan_baru')->assertSee('Lama usulan_perubahan_diterapkan')->assertSee('Lama usulan_perubahan_ditutup');
        $this->actingAs($user)->get(route('notifikasi.index'))->assertOk()->assertSee('Lama usulan_perubahan_ditutup');
        $c->assertOk();
    }

    /** Review Focus 1: log rows of the deleted model keep their label, show on the Log page and filter by it. */
    public function test_old_usulan_log_rows_render_on_the_log_page(): void
    {
        $admin = User::factory()->admin()->create();
        Aktivitas::create(['log_name' => 'default', 'description' => 'created', 'event' => 'created', 'subject_type' => self::KELAS_LAMA,
            'subject_id' => 999, 'properties' => ['attributes' => ['alasan' => 'Penanda usulan lama']]]);

        $this->assertSame('Usulan Perubahan', LabelAktivitas::objek(self::KELAS_LAMA));
        $this->assertSame(self::KELAS_LAMA, LabelAktivitas::kelas('pkpt_usulan'));
        $this->actingAs($admin)->get('/admin/log')->assertOk()->assertSee('Usulan Perubahan')->assertSee('Penanda usulan lama');
        Livewire::actingAs($admin)->test(LogAktivitas::class)->set('objek', 'pkpt_usulan')->assertOk()->assertSee('Penanda usulan lama');
    }
}
