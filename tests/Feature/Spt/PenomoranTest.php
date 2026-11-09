<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\AturNomorAwal;
use App\Actions\Spt\CatatNomorManual;
use App\Actions\Spt\TerimaPengajuan;
use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\NomorCounter;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PenomoranTest extends TestCase
{
    use RefreshDatabase;

    private function penomoran(): Penomoran
    {
        return app(Penomoran::class);
    }

    private function irban(string $kode = 'INSPEKTORAT-II'): Irban
    {
        return Irban::factory()->create(['kode_nomor' => $kode]);
    }

    private function kode(string $kode = '700'): KodeArsip
    {
        return KodeArsip::where('kode', $kode)->firstOrFail();
    }

    private function khusus(): Klasifikasi
    {
        return Klasifikasi::where('kode', 'KHUSUS')->firstOrFail();
    }

    private function ambil(Irban $irban, string $tanggal = '2026-05-04', ?Klasifikasi $klasifikasi = null, ?KodeArsip $kode = null): array
    {
        return DB::transaction(fn () => $this->penomoran()->ambil($irban, CarbonImmutable::parse($tanggal), $klasifikasi ?? $this->khusus(), $kode ?? $this->kode()));
    }

    private function pratinjau(Irban $irban, CarbonImmutable $tanggal, ?KodeArsip $kode = null): array
    {
        return $this->penomoran()->pratinjau($irban, $tanggal, $this->khusus(), $kode ?? $this->kode());
    }

    public function test_default_format_and_counter_auto_create(): void
    {
        $irban = $this->irban();
        $this->assertSame(0, NomorCounter::count());

        $hasil = $this->ambil($irban);

        $this->assertSame(1, $hasil['nomor_urut']);
        $this->assertSame('700/1/INSPEKTORAT-II/SPT/2026', $hasil['nomor_lengkap']);
        $this->assertSame(['tahun' => 2026, 'scope' => 'global', 'nomor_terakhir' => 1], $hasil['counter']->only(['tahun', 'scope', 'nomor_terakhir']));
        $this->assertSame(1, NomorCounter::count());

        $this->assertSame('700/2/INSPEKTORAT-II/SPT/2026', $this->ambil($irban)['nomor_lengkap']);
        $this->assertSame(1, NomorCounter::count());
    }

    public function test_example_number_from_plan(): void
    {
        $irban = $this->irban();
        $counter = NomorCounter::create(['tahun' => 2026, 'scope' => 'global']);
        $counter->forceFill(['nomor_terakhir' => 751])->save();

        $this->assertSame('700/752/INSPEKTORAT-II/SPT/2026', $this->ambil($irban)['nomor_lengkap']);
    }

    public function test_existing_counter_is_never_reset_by_the_create_step(): void
    {
        $irban = $this->irban();
        NomorCounter::create(['tahun' => 2026, 'scope' => 'global'])->forceFill(['nomor_terakhir' => 5])->save();

        $this->assertSame(6, $this->ambil($irban)['nomor_urut']);
        $this->assertSame(7, $this->ambil($irban)['nomor_urut']);
        $this->assertSame(1, NomorCounter::count());
        $this->assertSame(7, NomorCounter::first()->nomor_terakhir);
    }

    public function test_ambil_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);
        // RefreshDatabase wraps each test in a transaction; leave it to reach level 0.
        $level = DB::transactionLevel();
        for ($i = 0; $i < $level; $i++) {
            DB::rollBack();
        }
        try {
            $this->penomoran()->ambil(new Irban(['kode_nomor' => 'A']), CarbonImmutable::parse('2026-05-04'), new Klasifikasi, new KodeArsip);
        } finally {
            for ($i = 0; $i < $level; $i++) {
                DB::beginTransaction();
            }
        }
    }

    public function test_kode_arsip_and_custom_template(): void
    {
        $kode = KodeArsip::factory()->create(['kode' => '800.1']);
        app(Settings::class)->set('penomoran.format', 'SPT-{nomor_urut}-{kode_irban}-{tahun}-{kode_arsip}');

        $this->assertSame('SPT-1-IRB1-2026-800.1', $this->ambil($this->irban('IRB1'), kode: $kode)['nomor_lengkap']);
    }

    public function test_klasifikasi_placeholder_on_and_off(): void
    {
        $settings = app(Settings::class);
        $settings->set('penomoran.format', '{kode_arsip}/{nomor_urut}/{kode_irban}/{klasifikasi}/SPT/{tahun}');
        $irban = $this->irban('IRB1');
        $luar = Klasifikasi::where('kode', 'LUAR_DAERAH')->firstOrFail();

        $this->assertSame('700/1/IRB1/SPT/2026', $this->ambil($irban, klasifikasi: $luar)['nomor_lengkap']);

        $settings->set('penomoran.klasifikasi_di_nomor', true);
        $this->assertSame('700/2/IRB1/LUAR-DAERAH/SPT/2026', $this->ambil($irban, klasifikasi: $luar)['nomor_lengkap']);

        // Placeholder at the start: the slash after it goes.
        $settings->set('penomoran.klasifikasi_di_nomor', false);
        $settings->set('penomoran.format', '{klasifikasi}/{nomor_urut}/{tahun}');
        $this->assertSame('3/2026', $this->ambil($irban, klasifikasi: $luar)['nomor_lengkap']);
    }

    /** Fase 11.2 Task 6 butir 21: switched off, {klasifikasi} goes with one neighbouring separator of any kind, not only `/`. */
    public function test_klasifikasi_off_takes_any_separator_with_it(): void
    {
        $settings = app(Settings::class);
        $irban = $this->irban('IRB1');
        $luar = Klasifikasi::where('kode', 'LUAR_DAERAH')->firstOrFail();
        $kasus = ['{kode_arsip}/{nomor_urut}-{klasifikasi}/{tahun}' => '700/1/2026', '{nomor_urut}.{klasifikasi}.{tahun}' => '2.2026',
            '{klasifikasi}-{nomor_urut}/{tahun}' => '3/2026', '{nomor_urut}/{tahun}{klasifikasi}' => '4/2026', '{kode_arsip}/{klasifikasi}/{nomor_urut}/{tahun}' => '700/5/2026'];
        foreach ($kasus as $format => $nomor) {
            $settings->set('penomoran.format', $format);
            $this->assertSame($nomor, $this->ambil($irban, klasifikasi: $luar)['nomor_lengkap'], $format);
        }
        $settings->set('penomoran.klasifikasi_di_nomor', true); // control: switched on, the separators stay
        $settings->set('penomoran.format', '{kode_arsip}/{nomor_urut}-{klasifikasi}/{tahun}');
        $this->assertSame('700/6-LUAR-DAERAH/2026', $this->ambil($irban, klasifikasi: $luar)['nomor_lengkap']);
    }

    public function test_per_irban_scope_gives_independent_sequences(): void
    {
        app(Settings::class)->set('penomoran.scope', 'per_irban');
        $a = $this->irban('A');
        $b = $this->irban('B');

        $this->assertSame(1, $this->ambil($a)['nomor_urut']);
        $this->assertSame(2, $this->ambil($a)['nomor_urut']);
        $hasilB = $this->ambil($b);
        $this->assertSame(1, $hasilB['nomor_urut']);
        $this->assertSame("irban:{$b->id}", $hasilB['counter']->scope);
        $this->assertSame(3, $this->ambil($a)['nomor_urut']);
    }

    public function test_reset_tahunan(): void
    {
        $irban = $this->irban('A');
        $this->assertSame('700/1/A/SPT/2026', $this->ambil($irban, '2026-12-31')['nomor_lengkap']);
        $this->assertSame('700/1/A/SPT/2027', $this->ambil($irban, '2027-01-01')['nomor_lengkap']);

        app(Settings::class)->set('penomoran.reset_tahunan', false);
        $tanpaReset = $this->ambil($irban, '2027-01-02');
        $this->assertSame(0, $tanpaReset['counter']->tahun);
        $this->assertSame('700/1/A/SPT/2027', $tanpaReset['nomor_lengkap']);
        $this->assertSame('700/2/A/SPT/2028', $this->ambil($irban, '2028-03-01')['nomor_lengkap']);
    }

    public function test_pratinjau_equals_next_ambil_without_writing(): void
    {
        $irban = $this->irban('A');
        $tanggal = CarbonImmutable::parse('2026-05-04');

        $this->assertSame(['nomor_urut' => 1, 'nomor_lengkap' => '700/1/A/SPT/2026'], $this->pratinjau($irban, $tanggal));
        $this->assertSame(0, NomorCounter::count());

        $this->ambil($irban);
        $pratinjau = $this->pratinjau($irban, $tanggal);
        $this->assertSame(2, NomorCounter::first()->nomor_terakhir + 1);
        $berikut = $this->ambil($irban);
        $this->assertSame($pratinjau, ['nomor_urut' => $berikut['nomor_urut'], 'nomor_lengkap' => $berikut['nomor_lengkap']]);
    }

    public function test_cancelled_number_is_never_reissued(): void
    {
        $irban = $this->irban('A');
        foreach ([1, 2] as $_) {
            $hasil = $this->ambil($irban);
            Spt::factory()->for($irban)->create([
                'status_pengajuan' => StatusPengajuan::Diterima, 'nomor_urut' => $hasil['nomor_urut'],
                'nomor_lengkap' => $hasil['nomor_lengkap'], 'nomor_counter_id' => $hasil['counter']->id, 'tahun' => 2026,
            ]);
        }

        // The way BatalkanNomor will cancel: status only.
        $dua = Spt::where('nomor_urut', 2)->firstOrFail();
        $dua->forceFill(['status_pengajuan' => StatusPengajuan::Dibatalkan])->save();

        $this->assertSame(3, $this->ambil($irban)['nomor_urut']);
        $this->assertSame(['nomor_urut' => 2, 'nomor_lengkap' => '700/2/A/SPT/2026'], $dua->fresh()->only(['nomor_urut', 'nomor_lengkap']));
        $this->assertSame(3, NomorCounter::first()->nomor_terakhir);
    }

    public function test_atur_nomor_awal(): void
    {
        $admin = User::factory()->admin()->create();
        $irban = $this->irban('A');

        $counter = (new AturNomorAwal)->handle($admin, 2026, null, 751);
        $this->assertSame(['tahun' => 2026, 'scope' => 'global', 'nomor_terakhir' => 751, 'diatur_oleh' => $admin->id], $counter->only(['tahun', 'scope', 'nomor_terakhir', 'diatur_oleh']));
        $this->assertNotNull($counter->diatur_pada);

        // Still adjustable while no number was issued on it.
        $this->assertSame(760, (new AturNomorAwal)->handle($admin, 2026, null, 760)->nomor_terakhir);
        $this->assertSame(761, $this->ambil($irban)['nomor_urut']);

        $this->assertRejected(fn () => (new AturNomorAwal)->handle($admin, 2026, null, -1), 'nomor', 'Nomor awal minimal 0.');

        $hasil = $this->ambil($irban);
        Spt::factory()->for($irban)->diterima()->create(['nomor_urut' => $hasil['nomor_urut'], 'nomor_lengkap' => $hasil['nomor_lengkap'], 'nomor_counter_id' => $hasil['counter']->id]);
        $this->assertRejected(fn () => (new AturNomorAwal)->handle($admin, 2026, null, 900), 'nomor', 'Nomor awal hanya bisa diatur sebelum ada nomor yang terbit pada register ini.');

        // Another year is a separate register.
        $this->assertSame(10, (new AturNomorAwal)->handle($admin, 2027, null, 10)->nomor_terakhir);
    }

    public function test_atur_nomor_awal_per_irban_and_without_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $irban = $this->irban('A');
        $settings = app(Settings::class);

        $settings->set('penomoran.scope', 'per_irban');
        $this->assertSame("irban:{$irban->id}", (new AturNomorAwal)->handle($admin, 2026, $irban, 5)->scope);
        $this->assertSame(6, $this->ambil($irban)['nomor_urut']);
        $this->assertRejected(fn () => (new AturNomorAwal)->handle($admin, 2026, null, 5), 'irban_id', 'Pilih Irban.');

        $settings->set('penomoran.reset_tahunan', false);
        $this->assertSame(0, (new AturNomorAwal)->handle($admin, 2026, $irban, 3)->tahun);
    }

    /** Takes the next number and issues an SPT with it, as TerimaPengajuan does. */
    private function terbit(Irban $irban, string $tanggal = '2026-05-04', ?KodeArsip $kode = null): array
    {
        $hasil = $this->ambil($irban, $tanggal, kode: $kode);
        Spt::factory()->for($irban)->create([
            'status_pengajuan' => StatusPengajuan::Diterima, 'nomor_urut' => $hasil['nomor_urut'],
            'nomor_lengkap' => $hasil['nomor_lengkap'], 'nomor_counter_id' => $hasil['counter']->id, 'tahun' => (int) substr($tanggal, 0, 4),
            'kode_arsip_id' => ($kode ?? $this->kode())->id,
        ]);

        return $hasil;
    }

    /** Saves the penomoran card the way the Pengaturan page does. */
    private function simpanPenomoran(array $ubah): void
    {
        $settings = app(Settings::class);
        $nested = $settings->all();
        foreach ($ubah as $key => $value) {
            data_set($nested, $key, $value);
        }
        $settings->saveGroup('penomoran', $nested);
    }

    public function test_global_to_per_irban_mid_year_continues_from_the_highest_number(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Jakarta'));
        [$a, $b, $c] = [$this->irban('A'), $this->irban('B'), $this->irban('C')];
        $this->terbit($a);
        $this->terbit($a);
        $this->terbit($b);

        $this->simpanPenomoran(['penomoran.scope' => 'per_irban']);

        foreach ([$a, $b, $c] as $irban) {
            $this->assertSame(3, NomorCounter::where(['tahun' => 2026, 'scope' => "irban:{$irban->id}"])->value('nomor_terakhir'));
        }
        $this->assertSame(['nomor_urut' => 4, 'nomor_lengkap' => '700/4/A/SPT/2026'], $this->pratinjau($a, CarbonImmutable::parse('2026-05-04')));
        $this->assertSame('700/4/A/SPT/2026', $this->terbit($a)['nomor_lengkap']);
        $this->assertSame('700/4/C/SPT/2026', $this->terbit($c)['nomor_lengkap']);
        $this->assertSame(5, Spt::count());
    }

    public function test_per_irban_to_global_continues_from_the_highest_register(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Jakarta'));
        app(Settings::class)->set('penomoran.scope', 'per_irban');
        [$a, $b] = [$this->irban('A'), $this->irban('B')];
        foreach ([$a, $a, $a, $b] as $irban) {
            $this->terbit($irban);
        }

        $this->simpanPenomoran(['penomoran.scope' => 'global']);

        $this->assertSame(4, $this->terbit($b)['nomor_urut']);
        $this->assertSame(5, $this->terbit($a)['nomor_urut']);
    }

    public function test_reset_tahunan_on_off_on_never_reuses_a_stale_register(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Jakarta'));
        $a = $this->irban('A');
        $this->terbit($a);
        $this->terbit($a);

        $this->simpanPenomoran(['penomoran.reset_tahunan' => false]);
        $this->assertSame(3, $this->terbit($a)['nomor_urut']);
        $this->assertSame(4, $this->terbit($a)['nomor_urut']);

        $this->simpanPenomoran(['penomoran.reset_tahunan' => true]);
        $this->assertSame('700/5/A/SPT/2026', $this->terbit($a)['nomor_lengkap']);

        // Control: saving without a scope/reset change leaves the registers alone.
        $sebelum = NomorCounter::orderBy('id')->pluck('nomor_terakhir', 'id')->all();
        $this->simpanPenomoran(['penomoran.klasifikasi_di_nomor' => false]);
        $this->assertSame($sebelum, NomorCounter::orderBy('id')->pluck('nomor_terakhir', 'id')->all());
    }

    public function test_a_rolled_back_attempt_leaves_no_trace_so_the_retry_still_raises_the_registers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Jakarta'));
        $settings = app(Settings::class);
        $a = $this->irban('A');
        $this->terbit($a);
        $this->terbit($a);

        // DB::transaction(attempts: 3) reruns the same closure after a deadlock; under RefreshDatabase that transaction is nested and
        // Laravel does not retry it, so the test plays both attempts itself. The first fails while raising the registers, after
        // the new rule was written and read back. (A "Deadlock" message would also skip the savepoint rollback when nested.)
        $simpan = fn () => $settings->set('penomoran.scope', 'per_irban');
        $gagal = true;
        NomorCounter::saving(function () use (&$gagal) {
            if ($gagal) {
                $gagal = false;
                throw new RuntimeException('Percobaan pertama gagal');
            }
        });
        $this->assertThrows(fn () => $this->penomoran()->simpanAturan($simpan), RuntimeException::class, 'Percobaan pertama gagal');
        $this->assertSame('global', $settings->get('penomoran.scope'));
        $this->assertSame(0, Activity::where('log_name', 'pengaturan')->count());

        $this->penomoran()->simpanAturan($simpan); // the retry
        $this->assertSame(2, NomorCounter::where(['tahun' => 2026, 'scope' => "irban:{$a->id}"])->value('nomor_terakhir'));
        $this->assertSame(3, $this->terbit($a)['nomor_urut']);
        $this->assertSame(['penomoran.scope' => 'per_irban'], Activity::where('log_name', 'pengaturan')->sole()->properties['attributes']);
    }

    public function test_ambil_and_pratinjau_skip_a_nomor_lengkap_already_issued(): void
    {
        $settings = app(Settings::class);
        $a = $this->irban('A');
        $nol = KodeArsip::factory()->create(['kode' => '0']);
        $settings->set('penomoran.format', '{nomor_urut}{kode_arsip}/{tahun}');
        $this->assertSame('10/2026', $this->terbit($a, kode: $nol)['nomor_lengkap']);
        NomorCounter::first()->forceFill(['nomor_terakhir' => 9])->save();

        // The new format rebuilds "10/2026" at nomor 10, already issued under the old format.
        $settings->set('penomoran.format', '{nomor_urut}/{tahun}');
        $tanggal = CarbonImmutable::parse('2026-05-04');
        $this->assertSame(['nomor_urut' => 11, 'nomor_lengkap' => '11/2026'], $this->pratinjau($a, $tanggal, $nol));
        $this->assertSame(9, NomorCounter::first()->nomor_terakhir);
        $hasil = $this->terbit($a, kode: $nol);
        $this->assertSame(['nomor_urut' => 11, 'nomor_lengkap' => '11/2026'], ['nomor_urut' => $hasil['nomor_urut'], 'nomor_lengkap' => $hasil['nomor_lengkap']]);
        $this->assertSame(11, NomorCounter::first()->nomor_terakhir);
    }

    /** 1.2 Task 2: one register for every kode arsip; the kode only changes the prefix. */
    public function test_kode_arsip_berbeda_melanjutkan_urutan(): void
    {
        $irban = $this->irban('INSPEKTORAT-I');
        $this->assertSame('700/1/INSPEKTORAT-I/SPT/2026', $this->terbit($irban)['nomor_lengkap']);

        $tanggal = CarbonImmutable::parse('2026-05-04');
        $this->assertSame(['nomor_urut' => 2, 'nomor_lengkap' => '800/2/INSPEKTORAT-I/SPT/2026'], $this->pratinjau($irban, $tanggal, $this->kode('800')));
        $hasil = $this->terbit($irban, kode: $this->kode('800'));
        $this->assertSame([2, '800/2/INSPEKTORAT-I/SPT/2026'], [$hasil['nomor_urut'], $hasil['nomor_lengkap']]);
        $this->assertSame(1, NomorCounter::count());
    }

    public function test_ubah_kode_master_tidak_mengubah_nomor_terbit(): void
    {
        $irban = $this->irban('INSPEKTORAT-I');
        $this->terbit($irban);
        $this->kode()->update(['kode' => '701']);

        $this->assertSame('700/1/INSPEKTORAT-I/SPT/2026', Spt::sole()->nomor_lengkap);
        $this->assertSame('701/2/INSPEKTORAT-I/SPT/2026', $this->terbit($irban, kode: $this->kode('701'))['nomor_lengkap']);
    }

    /** Accepting a pengajuan and recording a manual number both take the kode of that SPT / of the form. */
    public function test_terima_dan_catat_manual_memakai_kode_arsip_spt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Jakarta'));
        $irban = $this->irban('INSPEKTORAT-I');
        $bu = User::factory()->bagianUmum()->create();
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $irban->id, 'kode_arsip_id' => $this->kode('900')->id]);

        $this->assertSame('900/1/INSPEKTORAT-I/SPT/2026', app(TerimaPengajuan::class)->handle($bu, $spt)->nomor_lengkap);

        $manual = app(CatatNomorManual::class)->handle($bu, [
            'irban_id' => $irban->id, 'klasifikasi_id' => $this->khusus()->id, 'kode_arsip_id' => $this->kode('800')->id, 'uraian_untuk' => 'Mendesak',
            'tanggal_mulai' => '2026-05-05', 'tanggal_selesai' => '2026-05-06', 'alasan' => 'Perintah mendadak dari Bupati',
        ]);
        $this->assertSame('800/2/INSPEKTORAT-I/SPT/2026', $manual->nomor_lengkap);
        $this->assertSame($this->kode('800')->id, $manual->kode_arsip_id);
    }

    public function test_migrasi_mengisi_kode_arsip(): void
    {
        $this->assertSame(['700' => 'Pengawasan Umum', '800' => 'Kepegawaian', '900' => 'Anggaran'], KodeArsip::orderBy('kode')->pluck('nama', 'kode')->all());
        // isi() is the data half of the migration (no DDL, so safe inside RefreshDatabase on MySQL) and is idempotent.
        $migrasi = require database_path('migrations/2026_12_20_000002_create_kode_arsip_table.php');

        // An old setting outside the three rows becomes its own row (kode = nama); every SPT without a kode gets it, the setting row goes.
        DB::table('settings')->insert(['key' => 'penomoran.kode_arsip', 'value' => json_encode('800.1'), 'created_at' => now(), 'updated_at' => now()]);
        $lama = Spt::factory()->diterima()->create(['kode_arsip_id' => null]);
        $sudah = Spt::factory()->create(['kode_arsip_id' => $this->kode('900')->id]);
        $migrasi->isi();
        $this->assertSame(['kode' => '800.1', 'nama' => '800.1'], $lama->fresh()->kodeArsip->only(['kode', 'nama']));
        $this->assertSame('900', $sudah->fresh()->kodeArsip->kode);
        $this->assertDatabaseMissing('settings', ['key' => 'penomoran.kode_arsip']);
        $this->assertSame(4, KodeArsip::count());

        // A setting equal to an existing kode reuses that row; no setting means 700.
        DB::table('settings')->insert(['key' => 'penomoran.kode_arsip', 'value' => json_encode('800'), 'created_at' => now(), 'updated_at' => now()]);
        $delapan = Spt::factory()->create(['kode_arsip_id' => null]);
        $migrasi->isi();
        $tujuh = Spt::factory()->create(['kode_arsip_id' => null]);
        $migrasi->isi();
        $this->assertSame(['800', '700'], [$delapan->fresh()->kodeArsip->kode, $tujuh->fresh()->kodeArsip->kode]);
        $this->assertSame(4, KodeArsip::count());
    }

    private function assertRejected(callable $fn, string $key, string $message): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()[$key][0] ?? null);
        }
    }
}
